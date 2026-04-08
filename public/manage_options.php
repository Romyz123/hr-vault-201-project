<?php
// ======================================================
// [FILE] public/manage_options.php
// [PURPOSE] Manage Dynamic Dropdown Options & Role Duties
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
    header("Location: index.php");
    exit;
}

// [NEW] Helper function for safe HTML output
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    // E. Disciplinary Violations
    $pdo->exec("CREATE TABLE IF NOT EXISTS disciplinary_violations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(50) NOT NULL,
        name VARCHAR(100) NOT NULL,
        description TEXT NULL,
        UNIQUE KEY unique_viol (category, name)
    )");
    if ($pdo->query("SELECT COUNT(*) FROM disciplinary_violations")->fetchColumn() == 0) {
        $vDefaults = [
            "Attendance" => ["Tardiness / Late", "AWOL (Absence Without Leave)", "Abandonment of Work", "Undertime"],
            "Conduct"    => ["Insubordination", "Disrespect to Superior", "Fighting / Assault", "Gambling on Premises"],
            "Honesty"    => ["Dishonesty", "Falsification of Records", "Theft", "Fraud"],
            "Safety"     => ["LSR Violation", "Non-use of PPE", "Unsafe Act", "Safety Negligence"],
            "Performance" => ["Negligence of Duty", "Sleeping on Duty", "Malingering", "Poor Work Performance"]
        ];
        $stmt = $pdo->prepare("INSERT INTO disciplinary_violations (category, name) VALUES (?, ?)");
        foreach ($vDefaults as $cat => $items) {
            foreach ($items as $item) try {
                $stmt->execute([$cat, $item]);
            } catch (Exception $e) {
            }
        }
    }

    // F. Company Rules
    $pdo->exec("CREATE TABLE IF NOT EXISTS company_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT NULL
    )");
    if ($pdo->query("SELECT COUNT(*) FROM company_rules")->fetchColumn() == 0) {
        $rDefaults = ["Rule I - Attendance and Punctuality", "Rule II - Conduct and Decorum", "Rule III - Safety and Health", "Rule IV - Company Property", "Rule V - Honesty and Integrity", "Rule VI - General Provisions", "Project-Specific Safety Protocol", "Data Privacy Policy"];
        $stmt = $pdo->prepare("INSERT INTO company_rules (name) VALUES (?)");
        foreach ($rDefaults as $r) try {
            $stmt->execute([$r]);
        } catch (Exception $e) {
        }
    }
} catch (PDOException $e) {
    die("Database Initialization Error: " . $e->getMessage());
}

// 3. HANDLE ACTIONS
$msg = "";
$error = "";
$activeTab = 'agency';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid CSRF Token";
    } else {
        $action = $_POST['action'] ?? '';
        $name   = strtoupper(trim($_POST['name'] ?? ''));
        $id     = (int)($_POST['id'] ?? 0);

        // Keep the active tab open
        if (strpos($action, 'agency') !== false) {
            $activeTab = 'agency';
        } elseif (strpos($action, 'role') !== false) {
            $activeTab = 'role';
        } elseif (strpos($action, 'dept') !== false || strpos($action, 'section') !== false) {
            $activeTab = 'dept';
        } elseif (strpos($action, 'violation') !== false) {
            $activeTab = 'violation';
        } elseif (strpos($action, 'rule') !== false) {
            $activeTab = 'rule';
        }

        if (strlen($name) > 100) $error = "Name is too long (Max 100 chars).";

        // --- AGENCIES ---
        if (empty($error) && $action === 'add_agency' && !empty($name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO agencies (name) VALUES (?)");
                $stmt->execute([$name]);
                $logger->log($_SESSION['user_id'], 'ADD_AGENCY', "Added agency: $name");
                $redirectMsg = "✅ Agency '$name' added successfully.";
            } catch (PDOException $e) {
                $error = "Error: Agency name already exists.";
            }
        } elseif (empty($error) && $action === 'edit_agency' && !empty($name) && $id > 0) {
            try {
                $stmt = $pdo->prepare("UPDATE agencies SET name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
                $logger->log($_SESSION['user_id'], 'EDIT_AGENCY', "Updated agency ID $id to $name");
                $redirectMsg = "✅ Agency updated successfully.";
            } catch (PDOException $e) {
                $error = "Error: Name already taken.";
            }
        } elseif ($action === 'delete_agency' && $id > 0) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE agency_name = (SELECT name FROM agencies WHERE id = ?)");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: There are employees assigned to this agency.";
            } else {
                $pdo->prepare("DELETE FROM agencies WHERE id = ?")->execute([$id]);
                $logger->log($_SESSION['user_id'], 'DELETE_AGENCY', "Deleted agency ID $id");
                $redirectMsg = "✅ Agency deleted.";
            }
        }

        // --- ROLES ---
        elseif (empty($error) && $action === 'add_role' && !empty($name)) {
            try {
                $pdo->prepare("INSERT INTO system_roles (name) VALUES (?)")->execute([$name]);
                $logger->log($_SESSION['user_id'], 'ADD_ROLE', "Added system role: $name");
                $redirectMsg = "✅ Role added.";
            } catch (Exception $e) {
                $error = "Role exists.";
            }
        } elseif ($action === 'delete_role' && $id > 0) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE system_role = (SELECT name FROM system_roles WHERE id = ?)");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: There are employees assigned to this role.";
            } else {
                $pdo->prepare("DELETE FROM system_roles WHERE id = ?")->execute([$id]);
                $logger->log($_SESSION['user_id'], 'DELETE_ROLE', "Deleted system role ID: $id");
                $redirectMsg = "✅ Role deleted.";
            }
        } elseif ($action === 'update_role_duties' && $id > 0) {
            $duties = trim($_POST['duties'] ?? '');
            if (strlen($duties) > 3000) {
                $error = "❌ Duties list is too long (Max 3000 characters).";
            } else {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM system_roles WHERE id = ?");
                $checkStmt->execute([$id]);
                if ($checkStmt->fetchColumn() == 0) {
                    $error = "❌ Role not found.";
                } else {
                    $duties = strip_tags($duties);
                    $stmt = $pdo->prepare("UPDATE system_roles SET duties = ? WHERE id = ?");
                    $stmt->execute([$duties, $id]);
                    $logger->log($_SESSION['user_id'], 'EDIT_ROLE', "Updated duties for Role ID $id");
                    $redirectMsg = "✅ Role duties updated successfully.";
                }
            }
        }

        // --- DEPARTMENTS ---
        elseif (empty($error) && $action === 'add_dept' && !empty($name)) {
            try {
                $pdo->prepare("INSERT INTO departments (name) VALUES (?)")->execute([$name]);
                $logger->log($_SESSION['user_id'], 'ADD_DEPT', "Added department: $name");
                $redirectMsg = "✅ Department added.";
            } catch (Exception $e) {
                $error = "Department exists.";
            }
        } elseif ($action === 'delete_dept' && $id > 0) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE dept = (SELECT name FROM departments WHERE id = ?)");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: Employees are assigned to this department.";
            } else {
                $pdo->prepare("DELETE FROM departments WHERE id = ?")->execute([$id]);
                $logger->log($_SESSION['user_id'], 'DELETE_DEPT', "Deleted department ID: $id");
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
                    $redirectMsg = "✅ Section added.";
                } catch (Exception $e) {
                    $error = "Section exists in this department.";
                }
            }
        } elseif ($action === 'delete_section' && $id > 0) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE section = (SELECT name FROM sections WHERE id = ?)");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: Employees are assigned to this section.";
            } else {
                $pdo->prepare("DELETE FROM sections WHERE id = ?")->execute([$id]);
                $logger->log($_SESSION['user_id'], 'DELETE_SECTION', "Deleted section ID: $id");
                $redirectMsg = "✅ Section deleted.";
            }
        }

        // --- VIOLATIONS ---
        elseif ($action === 'add_violation' && !empty($name)) {
            $cat = strtoupper(trim($_POST['category'] ?? 'GENERAL'));
            $desc = trim($_POST['description'] ?? '');

            if (strlen($cat) > 50) $error = "❌ Category name is too long (Max 50 chars).";
            elseif (strlen($name) > 100) $error = "❌ Violation name is too long (Max 100 chars).";
            elseif (strlen($desc) > 1000) $error = "❌ Description is too long (Max 1000 chars).";

            $chk = $pdo->prepare("SELECT id FROM disciplinary_violations WHERE name = ? AND category = ?");
            $chk->execute([$name, $cat]);
            if ($chk->fetch()) {
                $error = "❌ Violation '$name' already exists in category '$cat'.";
            }

            if (empty($error)) {
                $pdo->prepare("INSERT INTO disciplinary_violations (category, name, description) VALUES (?, ?, ?)")->execute([$cat, $name, $desc]);
                $redirectMsg = "✅ Violation added.";
            }
        } elseif ($action === 'delete_violation' && $id > 0) {
            $pdo->prepare("DELETE FROM disciplinary_violations WHERE id = ?")->execute([$id]);
            $redirectMsg = "✅ Violation removed.";
        } elseif ($action === 'edit_violation' && $id > 0) {
            $desc = trim($_POST['description'] ?? '');
            $cat = strtoupper(trim($_POST['category'] ?? ''));

            if (strlen($cat) > 50) $error = "❌ Category name is too long.";
            elseif (strlen($name) > 100) $error = "❌ Violation name is too long.";
            elseif (strlen($desc) > 1000) $error = "❌ Description is too long.";

            $chk = $pdo->prepare("SELECT id FROM disciplinary_violations WHERE name = ? AND category = ? AND id != ?");
            $chk->execute([$name, $cat, $id]);
            if ($chk->fetch()) {
                $error = "❌ Another violation with the name '$name' already exists in category '$cat'.";
            }

            if (empty($error)) {
                $pdo->prepare("UPDATE disciplinary_violations SET name = ?, category = ?, description = ? WHERE id = ?")->execute([$name, $cat, $desc, $id]);
                $redirectMsg = "✅ Violation updated.";
            }
        }

        // --- RULES ---
        elseif ($action === 'add_rule' && !empty($name)) {
            $desc = trim($_POST['description'] ?? '');
            if (strlen($name) > 100) $error = "❌ Rule name is too long.";
            elseif (strlen($desc) > 2000) $error = "❌ Rule description is too long (Max 2000 chars).";

            $chk = $pdo->prepare("SELECT id FROM company_rules WHERE name = ?");
            $chk->execute([$name]);
            if ($chk->fetch()) {
                $error = "❌ Rule '$name' already exists.";
            }

            if (empty($error)) {
                $pdo->prepare("INSERT INTO company_rules (name, description) VALUES (?, ?)")->execute([$name, $desc]);
                $redirectMsg = "✅ Rule added.";
            }
        } elseif ($action === 'delete_rule' && $id > 0) {
            $pdo->prepare("DELETE FROM company_rules WHERE id = ?")->execute([$id]);
            $redirectMsg = "✅ Rule removed.";
        } elseif ($action === 'edit_rule' && $id > 0) {
            $desc = trim($_POST['description'] ?? '');
            if (strlen($name) > 100) $error = "❌ Rule name is too long.";
            elseif (strlen($desc) > 2000) $error = "❌ Rule description is too long.";

            $chk = $pdo->prepare("SELECT id FROM company_rules WHERE name = ? AND id != ?");
            $chk->execute([$name, $id]);
            if ($chk->fetch()) {
                $error = "❌ Another rule with the name '$name' already exists.";
            }

            if (empty($error)) {
                $pdo->prepare("UPDATE company_rules SET name = ?, description = ? WHERE id = ?")->execute([$name, $desc, $id]);
                $redirectMsg = "✅ Rule updated.";
            }
        }

        // Regenerate CSRF token on success
        if (!empty($redirectMsg)) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header("Location: manage_options.php?msg=" . urlencode($redirectMsg) . "&tab=" . urlencode($activeTab));
            exit;
        }
    }
}

// 4. FETCH DATA
$agencies = $pdo->query("SELECT * FROM agencies ORDER BY name ASC")->fetchAll();
$roles    = $pdo->query("SELECT * FROM system_roles ORDER BY name ASC")->fetchAll();
$depts    = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
$vList    = $pdo->query("SELECT * FROM disciplinary_violations ORDER BY category, name")->fetchAll();
$rList    = $pdo->query("SELECT * FROM company_rules ORDER BY name")->fetchAll();

$sections = [];
$stmt = $pdo->query("SELECT s.id, s.name, s.department_id FROM sections s ORDER BY s.name ASC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $sections[$row['department_id']][] = $row;
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];
if (isset($_GET['tab'])) $activeTab = $_GET['tab'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage Options</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="uploads/tesp-logo.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
    <link rel="icon" type="image/png" href="../uploads/tesp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../uploads/tesp-logo.png">
    <link rel="apple-touch-icon" href="../uploads/tesp-logo.png">
</head>

<body class="bg-body-tertiary">
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

        <ul class="nav nav-tabs mb-4" id="optionTabs" role="tablist">
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'agency' ? 'active' : ''; ?> fw-bold" id="agency-tab" data-bs-toggle="tab" data-bs-target="#agency" type="button">🏢 Agencies</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'role' ? 'active' : ''; ?> fw-bold" id="role-tab" data-bs-toggle="tab" data-bs-target="#role" type="button">💼 System Roles & Duties</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'dept' ? 'active' : ''; ?> fw-bold" id="dept-tab" data-bs-toggle="tab" data-bs-target="#dept" type="button">📂 Departments & Sections</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'violation' ? 'active' : ''; ?> fw-bold text-danger" id="violation-tab" data-bs-toggle="tab" data-bs-target="#violation" type="button">⚠️ Violations</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'rule' ? 'active' : ''; ?> fw-bold text-danger" id="rule-tab" data-bs-toggle="tab" data-bs-target="#rule" type="button">📜 Company Rules</button></li>
        </ul>

        <div class="tab-content" id="optionTabsContent">

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

            <div class="tab-pane fade <?php echo $activeTab === 'dept' ? 'show active' : ''; ?>" id="dept" role="tabpanel">
                <div class="row">
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

            <div class="tab-pane fade <?php echo $activeTab === 'violation' ? 'show active' : ''; ?>" id="violation" role="tabpanel">
                <div class="card shadow-sm border-danger">
                    <div class="card-body">
                        <form method="POST" class="row g-2 mb-4 align-items-end p-3 bg-light border rounded">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_violation">
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Category</label>
                                <input type="text" name="category" class="form-control form-control-sm" placeholder="e.g. ATTENDANCE" required maxlength="50" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Violation Name</label>
                                <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Excessive Tardiness" required maxlength="100">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Policy Description</label>
                                <input type="text" name="description" class="form-control form-control-sm" placeholder="Optional details..." maxlength="1000">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-danger btn-sm w-100 fw-bold">Add Violation</button>
                            </div>
                        </form>
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>Category</th>
                                    <th>Violation</th>
                                    <th>Description</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vList as $v): ?>
                                    <tr>
                                        <td><span class="badge bg-secondary"><?php echo h($v['category']); ?></span></td>
                                        <td class="fw-bold"><?php echo h($v['name']); ?></td>
                                        <td class="small text-muted"><?php echo h($v['description']); ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this violation?');">
                                                <button type="button" class="btn btn-sm btn-outline-primary border-0 me-1"
                                                    onclick='editViolation(<?php echo $v["id"]; ?>, <?php echo h(json_encode($v["category"])); ?>, <?php echo h(json_encode($v["name"])); ?>, <?php echo h(json_encode($v["description"] ?? "")); ?>)'>
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete_violation"><input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?php echo $activeTab === 'rule' ? 'show active' : ''; ?>" id="rule" role="tabpanel">
                <div class="card shadow-sm border-danger">
                    <div class="card-body">
                        <form method="POST" class="row g-2 mb-4 align-items-end p-3 bg-light border rounded">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_rule">
                            <div class="col-md-5">
                                <label class="form-label fw-bold">Rule Name / Header</label>
                                <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Rule I - Section 1" required maxlength="100">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-bold">Full Rule Description</label>
                                <input type="text" name="description" class="form-control form-control-sm" placeholder="Reference text from handbook..." maxlength="2000">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-danger btn-sm w-100 fw-bold">Add Rule</button>
                            </div>
                        </form>
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>Rule Name</th>
                                    <th>Reference Description</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rList as $r): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo h($r['name']); ?></td>
                                        <td class="small text-muted"><?php echo h($r['description']); ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this rule?');">
                                                <button type="button" class="btn btn-sm btn-outline-primary border-0 me-1"
                                                    onclick='editRule(<?php echo $r["id"]; ?>, <?php echo h(json_encode($r["name"])); ?>, <?php echo h(json_encode($r["description"] ?? "")); ?>)'>
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
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
    </div>
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

    <div class="modal fade" id="editViolationModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Violation</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="edit_violation">
                    <input type="hidden" name="id" id="editViolId">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Category</label>
                        <input type="text" name="category" id="editViolCat" class="form-control" required maxlength="50">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Violation Name</label>
                        <input type="text" name="name" id="editViolName" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Policy Description</label>
                        <textarea name="description" id="editViolDesc" class="form-control" rows="4" maxlength="1000"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Violation</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="editRuleModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Company Rule</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="edit_rule">
                    <input type="hidden" name="id" id="editRuleId">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Rule Name / Header</label>
                        <input type="text" name="name" id="editRuleName" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Full Rule Description</label>
                        <textarea name="description" id="editRuleDesc" class="form-control" rows="6" maxlength="2000"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Rule</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script>
        const sections = <?php echo json_encode($sections); ?>;

        let dutiesModal;
        document.addEventListener("DOMContentLoaded", () => {
            dutiesModal = new bootstrap.Modal(document.getElementById('dutiesModal'));
        });

        function editDuties(id, name, currentDuties) {
            document.getElementById('modalRoleId').value = id;
            document.getElementById('modalRoleName').innerText = name;
            document.getElementById('modalDuties').value = currentDuties;
            dutiesModal.show();
        }

        function editViolation(id, cat, name, desc) {
            document.getElementById('editViolId').value = id;
            document.getElementById('editViolCat').value = cat;
            document.getElementById('editViolName').value = name;
            document.getElementById('editViolDesc').value = desc;
            new bootstrap.Modal(document.getElementById('editViolationModal')).show();
        }

        function editRule(id, name, desc) {
            document.getElementById('editRuleId').value = id;
            document.getElementById('editRuleName').value = name;
            document.getElementById('editRuleDesc').value = desc;
            new bootstrap.Modal(document.getElementById('editRuleModal')).show();
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

        document.querySelectorAll('input[name="name"]').forEach(input => {
            const parentId = input.closest('.tab-pane')?.id;
            if (parentId !== 'agency' && parentId !== 'dept') return;
            input.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        });

        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
    <script src="assets/dark_mode.js"></script>
</body>

</html>