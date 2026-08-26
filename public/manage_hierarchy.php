<?php
// ======================================================
// [FILE] public/manage_hierarchy.php
// [STATUS] FIXED: Added rigorous validation, character limits, & pattern checks
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';

// Ensure session is an array before interacting with it to prevent string offset errors
session_start();
if (!isset($_SESSION) || !is_array($_SESSION)) {
    $_SESSION = [];
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'] ?? '';

checkSessionTimeout($pdo);

if (!isset($_SESSION['user_id']) || strtoupper($_SESSION['role'] ?? '') !== 'ADMIN') {
    header("Location: login.php");
    exit;
}
$logger = new Logger($pdo);
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

// Ensure tables exist
$pdo->exec("CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    UNIQUE KEY unique_section (department_id, name)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS `groups` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
)");

// [SAFEGUARD] Automatically add/fix 'name' column if tables were created with older schemas
foreach (['departments', 'sections', 'groups'] as $tbl) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'name'")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($cols)) {
            $allCols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('group_name', $allCols)) {
                $pdo->exec("ALTER TABLE `$tbl` CHANGE COLUMN `group_name` `name` VARCHAR(100) NOT NULL");
            } elseif (in_array('dept_name', $allCols)) {
                $pdo->exec("ALTER TABLE `$tbl` CHANGE COLUMN `dept_name` `name` VARCHAR(100) NOT NULL");
            } else {
                $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `name` VARCHAR(100) NOT NULL");
            }
        }
    } catch (Exception $e) {
        // Silently catch if table structure check fails
    }
}

// Handle Addition & Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $csrfToken)) {
        $error = "Invalid CSRF token.";
    } else {
        $action = $_POST['action'];
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? '';
        $parent = $_POST['parent'] ?? NULL;

        if ($action === 'add' && !empty($name)) {
            // [VALIDATION & CHARACTER LIMITS]
            $name = strtoupper(trim(preg_replace('/\s+/', ' ', $name))); // Normalize inner spaces
            $maxLength = 50; // Strict professional character limit

            if (strlen($name) > $maxLength) {
                $error = "Name is too long. Maximum allowed length is $maxLength characters.";
            } elseif (strlen($name) < 2) {
                $error = "Name is too short. Minimum allowed length is 2 characters.";
            } elseif (!preg_match('/^[A-Z0-9\s\-\.\&]+$/', $name)) {
                $error = "Invalid characters detected. Only letters, numbers, spaces, hyphens (-), periods (.), and ampersands (&) are allowed.";
            } elseif ($type === 'section' && empty($parent)) {
                $error = "Please select a department for the new section.";
            }

            if (empty($error)) {
                try {
                    if ($type === 'dept') {
                        $stmt = $pdo->prepare("INSERT INTO departments (name) VALUES (?)");
                        $stmt->execute([$name]);
                        $logger->log($_SESSION['user_id'] ?? 0, 'ADD_DEPT', "Added dept: $name");
                    } elseif ($type === 'section') {
                        $stmt = $pdo->prepare("INSERT INTO sections (department_id, name) VALUES (?, ?)");
                        $stmt->execute([(int)$parent, $name]);
                        $logger->log($_SESSION['user_id'] ?? 0, 'ADD_SECTION', "Added section: $name");
                    } elseif ($type === 'group') {
                        $stmt = $pdo->prepare("INSERT INTO `groups` (name) VALUES (?)");
                        $stmt->execute([$name]);
                        $logger->log($_SESSION['user_id'] ?? 0, 'ADD_GROUP', "Added group: $name");
                    }
                    header("Location: manage_hierarchy.php?msg=" . urlencode("Added successfully."));
                    exit;
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        $error = "An entry with this name already exists.";
                    } else {
                        $error = "Database Error: " . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            $name = '';
            $type = '';

            $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
            $stmt->execute([$id]);
            $rowName = $stmt->fetchColumn();
            if ($rowName !== false && is_string($rowName)) {
                $type = 'dept';
                $name = $rowName;
            }

            if (!$name) {
                $stmt = $pdo->prepare("SELECT name FROM sections WHERE id = ?");
                $stmt->execute([$id]);
                $rowName = $stmt->fetchColumn();
                if ($rowName !== false && is_string($rowName)) {
                    $type = 'section';
                    $name = $rowName;
                }
            }

            if (!$name) {
                $stmt = $pdo->prepare("SELECT name FROM `groups` WHERE id = ?");
                $stmt->execute([$id]);
                $rowName = $stmt->fetchColumn();
                if ($rowName !== false && is_string($rowName)) {
                    $type = 'group';
                    $name = $rowName;
                }
            }

            if (!$name) {
                $error = "Item not found.";
            }

            if (empty($error)) {
                if ($type === 'dept') {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE dept = ? OR dept LIKE ? OR dept LIKE ? OR dept LIKE ?");
                    $chk->execute([$name, "$name, %", "%, $name", "%, $name, %"]);
                } elseif ($type === 'section') {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE section = ? OR section LIKE ? OR section LIKE ? OR section LIKE ?");
                    $chk->execute([$name, "$name, %", "%, $name", "%, $name, %"]);
                } else {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE `group` = ? OR `group` LIKE ? OR `group` LIKE ? OR `group` LIKE ?");
                    $chk->execute([$name, "$name, %", "%, $name", "%, $name, %"]);
                }

                if ($chk && $chk->fetchColumn() > 0) {
                    $error = "Cannot delete: employees assigned to '$name'.";
                } else {
                    if ($type === 'dept') {
                        $pdo->prepare("DELETE FROM departments WHERE id = ?")->execute([$id]);
                        $logger->log($_SESSION['user_id'] ?? 0, 'DELETE_DEPT', "Deleted dept: $name");
                    } elseif ($type === 'section') {
                        $pdo->prepare("DELETE FROM sections WHERE id = ?")->execute([$id]);
                        $logger->log($_SESSION['user_id'] ?? 0, 'DELETE_SECTION', "Deleted section: $name");
                    } elseif ($type === 'group') {
                        $pdo->prepare("DELETE FROM `groups` WHERE id = ?")->execute([$id]);
                        $logger->log($_SESSION['user_id'] ?? 0, 'DELETE_GROUP', "Deleted group: $name");
                    }
                    header("Location: manage_hierarchy.php?msg=" . urlencode("Deleted successfully."));
                    exit;
                }
            }
        }
    }
}

// BULLETPROOF FETCHING: Manually structuring arrays to guarantee correct structure
$depts = [];
$dQuery = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC");
if ($dQuery) {
    foreach ($dQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (is_array($row)) $depts[] = $row;
    }
}

$sections = [];
$sQuery = $pdo->query("SELECT s.id, s.department_id, s.name, d.name as dept_name FROM sections s JOIN departments d ON s.department_id = d.id ORDER BY d.name, s.name ASC");
if ($sQuery) {
    foreach ($sQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (is_array($row)) $sections[] = $row;
    }
}

$groups = [];
$gQuery = $pdo->query("SELECT id, name FROM `groups` ORDER BY name ASC");
if ($gQuery) {
    foreach ($gQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (is_array($row)) $groups[] = $row;
    }
}

require 'header.php';
?>

<div class="container mt-4">
    <h4 class="mb-4"><i class="bi bi-gear-fill"></i> Manage Organization Hierarchy</h4>

    <?php if ($msg): ?><div class="alert alert-success shadow-sm"><?php echo h($msg); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger shadow-sm"><?php echo h($error); ?></div><?php endif; ?>

    <div class="row">
        <!-- DEPARTMENTS -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">1. Departments</div>
                <div class="card-body">
                    <form method="POST" class="input-group mb-3">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="type" value="dept">
                        <input type="text" name="name" class="form-control" placeholder="New Dept (Max 50 chars)..." maxlength="50" pattern="[A-Za-z0-9\s\-\.\&]+" title="Alphanumeric, spaces, hyphens, periods, and ampersands only" required>
                        <button class="btn btn-success" type="submit">Add</button>
                    </form>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($depts as $d): ?>
                            <?php if (!is_array($d)) continue; ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo h($d['name'] ?? ''); ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                    <input type="hidden" name="id" value="<?php echo h($d['id'] ?? ''); ?>">
                                    <button class="btn btn-link text-danger p-0"><i class="bi bi-trash"></i></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- SECTIONS -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-info text-white">2. Sections</div>
                <div class="card-body">
                    <form method="POST" class="mb-3">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="type" value="section">
                        <div class="mb-2">
                            <select name="parent" class="form-select" required>
                                <option value="">-- Assign to Dept --</option>
                                <?php foreach ($depts as $d): ?>
                                    <?php if (!is_array($d)) continue; ?>
                                    <option value="<?php echo h($d['id'] ?? ''); ?>"><?php echo h($d['name'] ?? ''); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <input type="text" name="name" class="form-control" placeholder="New Section (Max 50 chars)..." maxlength="50" pattern="[A-Za-z0-9\s\-\.\&]+" title="Alphanumeric, spaces, hyphens, periods, and ampersands only" required>
                            <button class="btn btn-success" type="submit">Add</button>
                        </div>
                    </form>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($sections as $s): ?>
                            <?php if (!is_array($s)) continue; ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div><small class="text-muted d-block"><?php echo h($s['dept_name'] ?? ''); ?></small> <?php echo h($s['name'] ?? ''); ?></div>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                    <input type="hidden" name="id" value="<?php echo h($s['id'] ?? ''); ?>">
                                    <button class="btn btn-link text-danger p-0"><i class="bi bi-trash"></i></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- GROUPS -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white">3. Groups</div>
                <div class="card-body">
                    <form method="POST" class="input-group mb-3">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                        <input type="hidden" name="type" value="group">
                        <input type="text" name="name" class="form-control" placeholder="New Group (Max 50 chars)..." maxlength="50" pattern="[A-Za-z0-9\s\-\.\&]+" title="Alphanumeric, spaces, hyphens, periods, and ampersands only" required>
                        <button class="btn btn-success" type="submit">Add</button>
                    </form>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($groups as $g): ?>
                            <?php if (!is_array($g)) continue; ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo h($g['name'] ?? ''); ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                                    <input type="hidden" name="id" value="<?php echo h($g['id'] ?? ''); ?>">
                                    <button class="btn btn-link text-danger p-0"><i class="bi bi-trash"></i></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

</div> <!-- End of container -->

<?php require 'footer.php'; ?>