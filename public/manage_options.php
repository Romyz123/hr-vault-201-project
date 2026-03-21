<?php
// ======================================================
// [FILE] public/manage_options.php
// [PURPOSE] Manage Dynamic Dropdown Options & Role Duties
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php'; // Standardized capitalization
session_start();

// 1. SECURITY
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
    header("Location: index.php");
    exit;
}

$security = new Security($pdo);
$logger   = new Logger($pdo);

// [SECURITY] CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// [AUTO-UPGRADE] Ensure 'duties' column exists in system_roles
try {
    $pdo->query("SELECT duties FROM system_roles LIMIT 1");
} catch (Exception $e) {
    // Column missing? Add it automatically.
    $pdo->exec("ALTER TABLE system_roles ADD COLUMN duties TEXT DEFAULT NULL");
}

// 2. AUTO-INIT DATABASE TABLES & SEEDING
try {
    // A. Agencies
    $pdo->exec("CREATE TABLE IF NOT EXISTS agencies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    )");
    if ($pdo->query("SELECT COUNT(*) FROM agencies")->fetchColumn() == 0) {
        $defaults = ["TESP DIRECT", "GUNJIN", "JORATECH", "UNLISOLUTIONS", "OTHERS - SUBCONS"];
        $stmt = $pdo->prepare("INSERT INTO agencies (name) VALUES (?)");
        foreach ($defaults as $d) try {
            $stmt->execute([$d]);
        } catch (Exception $e) {
        }
    }

    // B. System Roles
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    )");
    if ($pdo->query("SELECT COUNT(*) FROM system_roles")->fetchColumn() == 0) {
        $defaults = ["Manager", "Head", "Advisor", "Engineer", "Technician", "Officer", "IT", "Driver", "Staff", "Maintenance"];
        $stmt = $pdo->prepare("INSERT INTO system_roles (name) VALUES (?)");
        foreach ($defaults as $d) try {
            $stmt->execute([$d]);
        } catch (Exception $e) {
        }
    }

    // C. Departments
    $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    )");

    // D. Sections
    $pdo->exec("CREATE TABLE IF NOT EXISTS sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
        UNIQUE KEY unique_section (department_id, name)
    )");

    // Seed Departments & Sections if empty
    if ($pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn() == 0) {
        // Default Map from options.php logic
        $seedMap = [
            "SQP"     => ["GENERAL", "SAFETY", "QA", "PLANNING", "IT"],
            "ADMIN"   => ["GENERAL", "GAG", "TKG", "PCG", "ACG", "MED", "CLEANERS"],
            "OP"      => ["OFFICE OF THE PRESIDENT"],
            "SIGCOM"  => ["SIGNALING & COMMUNICATION"],
            "PSS"     => ["POWER SUPPLY SECTION"],
            "OCS"     => ["OVERHEAD CATENARY SYSTEM"],
            "MHI"     => ["MITSUBISHI HEAVY INDUSTRIES"],
            "HMS"     => ["HEAVY MAINTENANCE SECTION"],
            "RAS"     => ["ROOT CAUSE ANALYSIS"],
            "TRS"     => ["TECHNICAL RESEARCH SECTION"],
            "LMS"     => ["LIGHT MAINTENANCE SECTION"],
            "DOS"     => ["GENERAL", "CCRE", "SHUNTER", "DOS_OFF", "GEN_SUP"],
            "CTS"     => ["CIVIL TRACKS SECTION"],
            "BFS"     => ["GENERAL", "DEPOT_EQ", "CONVEY", "MOTOR"],
            "WHS"     => ["WAREHOUSE SECTION"],
            "GUNJIN"  => ["EMT", "SECURITY"],
            "SUBCONS-OTHERS" => ["OTHERS"]
        ];

        $deptStmt = $pdo->prepare("INSERT INTO departments (name) VALUES (?)");
        $sectStmt = $pdo->prepare("INSERT INTO sections (department_id, name) VALUES (?, ?)");

        foreach ($seedMap as $dept => $sections) {
            try {
                $deptStmt->execute([$dept]);
                $deptId = $pdo->lastInsertId();
                foreach ($sections as $sect) {
                    try {
                        $sectStmt->execute([$deptId, $sect]);
                    } catch (Exception $e) {
                    }
                }
            } catch (Exception $e) {
            }
        }
    }
} catch (PDOException $e) {
    die("Database Initialization Error: " . $e->getMessage());
}

// 3. HANDLE ACTIONS
$msg = "";
$error = "";
$activeTab = 'agency'; // Default tab

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid CSRF Token";
    } else {
        $action = $_POST['action'] ?? '';
        $name   = strtoupper(trim($_POST['name'] ?? ''));
        $id     = (int)($_POST['id'] ?? 0);

        // [UX] Keep the active tab open based on the action performed
        if (strpos($action, 'agency') !== false) {
            $activeTab = 'agency';
        } elseif (strpos($action, 'role') !== false) {
            $activeTab = 'role';
        } elseif (strpos($action, 'dept') !== false || strpos($action, 'section') !== false) {
            $activeTab = 'dept';
        }

        // [SECURITY] Validate Name Length
        if (strlen($name) > 100) $error = "Name is too long (Max 100 chars).";

        // --- AGENCIES ---
        if (empty($error) && $action === 'add_agency' && !empty($name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO agencies (name) VALUES (?)");
                $stmt->execute([$name]);
                $logger->log($_SESSION['user_id'], 'ADD_AGENCY', "Added agency: $name");
                $msg = "✅ Agency '$name' added successfully.";
                $redirectMsg = "✅ Agency '$name' added successfully.";
            } catch (PDOException $e) {
                $error = "Error: Agency name already exists.";
            }
        } elseif (empty($error) && $action === 'edit_agency' && !empty($name) && $id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE agencies SET name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
                $logger->log($_SESSION['user_id'], 'EDIT_AGENCY', "Updated agency ID $id to $name");
                $msg = "✅ Agency updated successfully.";
                $redirectMsg = "✅ Agency updated successfully.";
            } catch (PDOException $e) {
                $error = "Error: Name already taken.";
            }
        } elseif ($action === 'delete_agency' && $id > 0) {
            // Check usage before delete
            $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE agency_name = (SELECT name FROM agencies WHERE id = ?)");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: There are employees assigned to this agency.";
            } else {
                $pdo->prepare("DELETE FROM agencies WHERE id = ?")->execute([$id]);
                $logger->log($_SESSION['user_id'], 'DELETE_AGENCY', "Deleted agency ID $id");
                $msg = "✅ Agency deleted.";
                $redirectMsg = "✅ Agency deleted.";
            }
        }

        // --- ROLES ---
        elseif (empty($error) && $action === 'add_role' && !empty($name)) {
            try {
                $pdo->prepare("INSERT INTO system_roles (name) VALUES (?)")->execute([$name]);
                $logger->log($_SESSION['user_id'], 'ADD_ROLE', "Added system role: $name");
                $msg = "✅ Role added.";
                $redirectMsg = "✅ Role added.";
            } catch (Exception $e) {
                $error = "Role exists.";
            }
        } elseif ($action === 'delete_role' && $id > 0) {
            // Check usage before delete
            $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE system_role = (SELECT name FROM system_roles WHERE id = ?)");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: There are employees assigned to this role.";
            } else {
                $pdo->prepare("DELETE FROM system_roles WHERE id = ?")->execute([$id]);
                $logger->log($_SESSION['user_id'], 'DELETE_ROLE', "Deleted system role ID: $id");
                $msg = "✅ Role deleted.";
                $redirectMsg = "✅ Role deleted.";
            }
        } elseif ($action === 'update_role_duties' && $id > 0) {
            // [NEW] Logic to update duties with Validation
            $duties = trim($_POST['duties'] ?? '');

            if (strlen($duties) > 3000) {
                $error = "❌ Duties list is too long (Max 3000 characters).";
            } else {
                // Verify role exists
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM system_roles WHERE id = ?");
                $checkStmt->execute([$id]);
                if ($checkStmt->fetchColumn() == 0) {
                    $error = "❌ Role not found.";
                } else {
                    $duties = strip_tags($duties); // Remove HTML tags for safety
                    $stmt = $pdo->prepare("UPDATE system_roles SET duties = ? WHERE id = ?");
                    $stmt->execute([$duties, $id]);
                    $logger->log($_SESSION['user_id'], 'EDIT_ROLE', "Updated duties for Role ID $id");
                    $msg = "✅ Role duties updated successfully.";
                    $redirectMsg = "✅ Role duties updated successfully.";
                }
            }
        }

        // --- DEPARTMENTS ---
        elseif (empty($error) && $action === 'add_dept' && !empty($name)) {
            try {
                $pdo->prepare("INSERT INTO departments (name) VALUES (?)")->execute([$name]);
                $logger->log($_SESSION['user_id'], 'ADD_DEPT', "Added department: $name");
                $msg = "✅ Department added.";
                $redirectMsg = "✅ Department added.";
            } catch (Exception $e) {
                $error = "Department exists.";
            }
        } elseif ($action === 'delete_dept' && $id > 0) {
            // Check usage
            $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE dept = (SELECT name FROM departments WHERE id = ?)");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: Employees are assigned to this department.";
            } else {
                $pdo->prepare("DELETE FROM departments WHERE id = ?")->execute([$id]);
                $logger->log($_SESSION['user_id'], 'DELETE_DEPT', "Deleted department ID: $id");
                $msg = "✅ Department deleted.";
                $redirectMsg = "✅ Department deleted.";
            }
        }

        // --- SECTIONS ---
        elseif (empty($error) && $action === 'add_section' && !empty($name)) {
            $deptId = (int)$_POST['dept_id'];
            if ($deptId > 0) {
                try {
                    $pdo->prepare("INSERT INTO sections (department_id, name) VALUES (?, ?)")->execute([$deptId, $name]);
                    $logger->log($_SESSION['user_id'], 'ADD_SECTION', "Added section '$name' to Dept ID: $deptId");
                    $msg = "✅ Section added.";
                    $redirectMsg = "✅ Section added.";
                } catch (Exception $e) {
                    $error = "Section exists in this department.";
                }
            }
        } elseif ($action === 'delete_section' && $id > 0) {
            // Check usage before delete
            $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE section = (SELECT name FROM sections WHERE id = ?)");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: Employees are assigned to this section.";
            } else {
                $pdo->prepare("DELETE FROM sections WHERE id = ?")->execute([$id]);
                $logger->log($_SESSION['user_id'], 'DELETE_SECTION', "Deleted section ID: $id");
                $msg = "✅ Section deleted.";
                $redirectMsg = "✅ Section deleted.";
            }
        }

        // [SECURITY] Regenerate CSRF token on success to prevent replay attacks
        if (!empty($msg)) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // [FIX] Redirect on Success (PRG Pattern)
        if (!empty($redirectMsg)) {
            // Pass active tab to keep user in context
            header("Location: manage_options.php?msg=" . urlencode($redirectMsg) . "&tab=" . urlencode($activeTab));
            exit;
        }
    }
}

// 4. FETCH DATA
$agencies = $pdo->query("SELECT * FROM agencies ORDER BY name ASC")->fetchAll(); // [UX] Alphabetical
$roles    = $pdo->query("SELECT * FROM system_roles ORDER BY name ASC")->fetchAll(); // [UX] Alphabetical
$depts    = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll(); // [UX] Alphabetical

// Fetch sections grouped by dept
$sections = [];
$stmt = $pdo->query("SELECT s.id, s.name, s.department_id FROM sections s ORDER BY s.name ASC"); // [UX] Alphabetical
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $sections[$row['department_id']][] = $row;
}

// [FIX] Handle GET messages
if (isset($_GET['msg'])) $msg = $_GET['msg'];
if (isset($_GET['tab'])) $activeTab = $_GET['tab'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage Options</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
</head>

<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white"><i class="bi bi-list-check"></i> Manage Options</span>
            </div>
        </div>
    </nav>

    <div class="container mt-5">

        <?php if ($msg): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- TABS -->
        <ul class="nav nav-tabs mb-4" id="optionTabs" role="tablist">
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'agency' ? 'active' : ''; ?> fw-bold" id="agency-tab" data-bs-toggle="tab" data-bs-target="#agency" type="button">🏢 Agencies</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'role' ? 'active' : ''; ?> fw-bold" id="role-tab" data-bs-toggle="tab" data-bs-target="#role" type="button">💼 System Roles & Duties</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'dept' ? 'active' : ''; ?> fw-bold" id="dept-tab" data-bs-toggle="tab" data-bs-target="#dept" type="button">📂 Departments & Sections</button></li>
        </ul>

        <div class="tab-content" id="optionTabsContent">

            <!-- TAB 1: AGENCIES -->
            <div class="tab-pane fade <?php echo $activeTab === 'agency' ? 'show active' : ''; ?>" id="agency" role="tabpanel">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <form method="POST" class="row g-2 mb-4 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_agency">
                            <div class="col-md-9">
                                <label class="form-label fw-bold">Add New Agency</label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. NEW AGENCY INC." required maxlength="100" pattern="[A-Za-z0-9 \-\.]+" title="Alphanumeric, spaces, dashes, dots">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-lg"></i> Add</button>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Agency Name</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($agencies as $a): ?>
                                        <tr>
                                            <td>
                                                <form method="POST" class="d-flex gap-2">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="edit_agency">
                                                    <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                                                    <input type="text" name="name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($a['name']); ?>" required maxlength="100" pattern="[A-Za-z0-9 \-\.]+" title="Alphanumeric, spaces, dashes, dots">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-save"></i></button>
                                                </form>
                                            </td>
                                            <td class="text-end">
                                                <form method="POST" onsubmit="return confirm('Delete this agency?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="delete_agency">
                                                    <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 2: ROLES -->
            <div class="tab-pane fade <?php echo $activeTab === 'role' ? 'show active' : ''; ?>" id="role" role="tabpanel">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <form method="POST" class="row g-2 mb-4 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_role">
                            <div class="col-md-9">
                                <label class="form-label fw-bold">Add New Role</label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. SUPERVISOR" required maxlength="100" pattern="[A-Za-z0-9 \-\.]+" title="Alphanumeric, spaces, dashes, dots">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-lg"></i> Add</button>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 25%;">Role Name</th>
                                        <th>Contract Duties (Bullet Points)</th>
                                        <th style="width: 100px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($roles as $r): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($r['name']); ?></td>
                                            <td>
                                                <small class="text-muted d-block text-truncate" style="max-width: 400px;">
                                                    <?php echo !empty($r['duties']) ? str_replace("\n", " • ", substr($r['duties'], 0, 100)) . '...' : 'No duties defined.'; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-2">
                                                    <button type="button" class="btn btn-sm btn-primary"
                                                        data-role-id="<?php echo $r['id']; ?>"
                                                        data-role-name="<?php echo htmlspecialchars($r['name'], ENT_QUOTES); ?>"
                                                        data-role-duties="<?php echo htmlspecialchars($r['duties'] ?? '', ENT_QUOTES); ?>"
                                                        onclick="editDuties(this.dataset.roleId, this.dataset.roleName, this.dataset.roleDuties)">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>

                                                    <form method="POST" onsubmit="return confirm('Delete this role?');" class="m-0">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="delete_role">
                                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: DEPARTMENTS & SECTIONS -->
            <div class="tab-pane fade <?php echo $activeTab === 'dept' ? 'show active' : ''; ?>" id="dept" role="tabpanel">
                <div class="row">
                    <!-- DEPARTMENTS -->
                    <div class="col-md-5">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-dark text-white">Departments</div>
                            <div class="card-body">
                                <form method="POST" class="input-group mb-3">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="add_dept">
                                    <input type="text" name="name" class="form-control" placeholder="New Dept" required maxlength="100" pattern="[A-Za-z0-9 \-\.]+" title="Alphanumeric, spaces, dashes, dots">
                                    <button class="btn btn-success" type="submit"><i class="bi bi-plus-lg"></i></button>
                                </form>
                                <div class="list-group" id="deptList">
                                    <?php foreach ($depts as $d): ?>
                                        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                            <a href="#" class="text-decoration-none text-dark flex-grow-1" onclick="showSections(<?php echo $d['id']; ?>, '<?php echo htmlspecialchars($d['name']); ?>'); return false;">
                                                <strong><?php echo htmlspecialchars($d['name']); ?></strong>
                                            </a>
                                            <form method="POST" onsubmit="return confirm('Delete Department? This will delete all its sections.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete_dept">
                                                <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTIONS -->
                    <div class="col-md-7">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-secondary text-white d-flex justify-content-between">
                                <span id="sectTitle">Select a Department</span>
                            </div>
                            <div class="card-body">
                                <div id="sectContent" style="display:none;">
                                    <form method="POST" class="input-group mb-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="add_section">
                                        <input type="hidden" name="dept_id" id="activeDeptId">
                                        <input type="text" name="name" class="form-control" placeholder="New Section Name" required maxlength="100" pattern="[A-Za-z0-9 \-\.]+" title="Alphanumeric, spaces, dashes, dots">
                                        <button class="btn btn-success" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
                                    </form>
                                    <ul class="list-group" id="sectList">
                                        <!-- Populated by JS -->
                                    </ul>
                                </div>
                                <div id="sectPlaceholder" class="text-muted text-center mt-5">
                                    <i class="bi bi-arrow-left-circle fs-1"></i><br>Click a department on the left to manage sections.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- DUTIES MODAL -->
    <div class="modal fade" id="dutiesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Edit Duties: <span id="modalRoleName" class="fw-bold"></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="update_role_duties">
                        <input type="hidden" name="id" id="modalRoleId">

                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle"></i> Enter each duty on a <strong>new line</strong>. These will appear as bullet points in the contract.
                        </div>
                        <textarea name="duties" id="modalDuties" class="form-control" rows="10" placeholder="e.g.&#10;Perform daily checks.&#10;Submit reports on time." maxlength="3000"></textarea>
                        <div class="form-text text-end">Max 3000 characters.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-info text-white" onclick="previewDuties()"><i class="bi bi-eye"></i> Preview</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Duties</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script>
        // Section Data from PHP
        const sections = <?php echo json_encode($sections); ?>;

        // DUTIES MODAL LOGIC
        const dutiesModal = new bootstrap.Modal(document.getElementById('dutiesModal'));

        function editDuties(id, name, currentDuties) {
            document.getElementById('modalRoleId').value = id;
            document.getElementById('modalRoleName').innerText = name;
            document.getElementById('modalDuties').value = currentDuties;
            dutiesModal.show();
        }

        function previewDuties() {
            const text = document.getElementById('modalDuties').value;
            if (!text.trim()) {
                Swal.fire('Empty', 'No duties to preview.', 'info');
                return;
            }

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            const lines = text.split('\n').filter(line => line.trim() !== '');
            let html = '<ul class="text-start">';
            lines.forEach(line => {
                html += `<li>${escapeHtml(line)}</li>`;
            });
            html += '</ul>';

            Swal.fire({
                title: 'Contract Preview',
                html: html,
                icon: 'info',
                confirmButtonText: 'Close Preview'
            });
        }

        function showSections(deptId, deptName) {
            document.getElementById('sectTitle').innerText = 'Sections for: ' + deptName;
            document.getElementById('activeDeptId').value = deptId;
            document.getElementById('sectContent').style.display = 'block';
            document.getElementById('sectPlaceholder').style.display = 'none';

            const list = document.getElementById('sectList');
            list.innerHTML = '';

            const deptSections = sections[deptId] || [];
            if (deptSections.length === 0) {
                list.innerHTML = '<li class="list-group-item text-muted text-center">No sections found.</li>';
            } else {
                deptSections.forEach(s => {
                    const li = document.createElement('li');
                    li.className = 'list-group-item d-flex justify-content-between align-items-center';
                    li.innerHTML = `
                        <span>${s.name}</span>
                        <form method="POST" onsubmit="return confirm('Delete this section?');" class="m-0">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="delete_section">
                            <input type="hidden" name="id" value="${s.id}">
                            <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                        </form>
                    `;
                    list.appendChild(li);
                });
            }
        }

        // Auto-capitalize inputs
        document.querySelectorAll('input[name="name"]').forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        });

        // Clear URL params on load
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
    <script src="dark_mode.js"></script>
</body>

</html>