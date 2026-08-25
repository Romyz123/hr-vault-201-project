<?php
// ======================================================
// [FILE] public/manage_hierarchy.php
// [STATUS] NEW: Separate Management for Dept, Section, Group
// ======================================================
// [FIX] Hardened: every table read is guarded so a missing table
// (e.g. `groups`) or odd row shape can never fatal this page.
// A fatal here also prevented footer.php (which loads the Bootstrap
// bundle) from rendering, which is why the navbar dropdowns died
// on this page only.

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
checkSessionTimeout($pdo);

if (!isset($_SESSION['user_id']) || strtoupper($_SESSION['role'] ?? '') !== 'ADMIN') {
    header("Location: login.php");
    exit;
}
$logger = new Logger($pdo);
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

// [FIX] Safe name extraction that works for associative rows, objects, or plain values.
function hierarchyRowName($row): string
{
    if (is_object($row)) {
        $row = get_object_vars($row);
    }
    if (is_array($row)) {
        foreach (['name', 'title', 'label'] as $key) {
            if (isset($row[$key]) && is_scalar($row[$key])) {
                return (string)$row[$key];
            }
        }
        foreach ($row as $value) {
            if (is_scalar($value) && $value !== '') {
                return (string)$value;
            }
        }
        return '';
    }
    return is_scalar($row) ? (string)$row : '';
}

// [FIX] Column-existence probe so old schemas (no `group` column on employees)
// don't fatal during the delete-in-use check.
function employeesHasColumn(PDO $pdo, string $column): bool
{
    static $cache = [];
    if (!array_key_exists($column, $cache)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = ?");
            $stmt->execute([$column]);
            $cache[$column] = ((int)$stmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            $cache[$column] = true; // Assume the column exists; let the real query surface the problem.
        }
    }
    return $cache[$column];
}

// Handle Addition / Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $error = "Invalid CSRF token.";
    } else {
        $action = $_POST['action'];
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? ''; // dept, section, group
        $parent = $_POST['parent'] ?? NULL; // For sections

        try {
            if ($action === 'add' && !empty($name)) {
                $name = strtoupper(trim($name));
                if (strlen($name) > 100) {
                    $error = "Name is too long (max 100 characters).";
                } elseif (!preg_match('/^[A-Z0-9\s\-\.]+$/', $name)) {
                    $error = "Name contains invalid characters.";
                } elseif ($type === 'section' && empty($parent)) {
                    $error = "Please select a department for the new section.";
                }

                if (empty($error)) {
                    if ($type === 'dept') {
                        $stmt = $pdo->prepare("INSERT INTO departments (name) VALUES (?)");
                        $stmt->execute([$name]);
                        $logger->log($_SESSION['user_id'], 'ADD_DEPT', "Added dept: $name");
                    } elseif ($type === 'section') {
                        $stmt = $pdo->prepare("INSERT INTO sections (department_id, name) VALUES (?, ?)");
                        $stmt->execute([(int)$parent, $name]);
                        $logger->log($_SESSION['user_id'], 'ADD_SECTION', "Added section: $name");
                    } elseif ($type === 'group') {
                        $stmt = $pdo->prepare("INSERT INTO groups (name) VALUES (?)");
                        $stmt->execute([$name]);
                        $logger->log($_SESSION['user_id'], 'ADD_GROUP', "Added group: $name");
                    } else {
                        $error = "Unknown hierarchy type.";
                    }

                    if (empty($error)) {
                        header("Location: manage_hierarchy.php?msg=" . urlencode("Added successfully."));
                        exit;
                    }
                }
            } elseif ($action === 'delete' && isset($_POST['id'])) {
                $id = (int)$_POST['id'];
                $name = '';
                $type = '';

                $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                $stmt->execute([$id]);
                $rowName = $stmt->fetchColumn();
                if ($rowName !== false) {
                    $type = 'dept';
                    $name = (string)$rowName;
                }

                if (!$name) {
                    $stmt = $pdo->prepare("SELECT name FROM sections WHERE id = ?");
                    $stmt->execute([$id]);
                    $rowName = $stmt->fetchColumn();
                    if ($rowName !== false) {
                        $type = 'section';
                        $name = (string)$rowName;
                    }
                }

                if (!$name) {
                    $stmt = $pdo->prepare("SELECT name FROM groups WHERE id = ?");
                    $stmt->execute([$id]);
                    $rowName = $stmt->fetchColumn();
                    if ($rowName !== false) {
                        $type = 'group';
                        $name = (string)$rowName;
                    }
                }

                if (!$name) {
                    $error = "Item not found.";
                }

                if (empty($error)) {
                    // In-use check: only when the employees column actually exists in this schema.
                    if ($type === 'dept' && employeesHasColumn($pdo, 'dept')) {
                        $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE dept = ? OR dept LIKE ? OR dept LIKE ? OR dept LIKE ?");
                        $chk->execute([$name, "$name, %", "%, $name", "%, $name, %"]);
                        if ($chk->fetchColumn() > 0) {
                            $error = "Cannot delete: employees assigned to '$name'.";
                        }
                    } elseif ($type === 'section' && employeesHasColumn($pdo, 'section')) {
                        $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE section = ? OR section LIKE ? OR section LIKE ? OR section LIKE ?");
                        $chk->execute([$name, "$name, %", "%, $name", "%, $name, %"]);
                        if ($chk->fetchColumn() > 0) {
                            $error = "Cannot delete: employees assigned to '$name'.";
                        }
                    } elseif ($type === 'group' && employeesHasColumn($pdo, 'group')) {
                        $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE `group` = ? OR `group` LIKE ? OR `group` LIKE ? OR `group` LIKE ?");
                        $chk->execute([$name, "$name, %", "%, $name", "%, $name, %"]);
                        if ($chk->fetchColumn() > 0) {
                            $error = "Cannot delete: employees assigned to '$name'.";
                        }
                    }

                    if (empty($error)) {
                        if ($type === 'dept') {
                            $pdo->prepare("DELETE FROM departments WHERE id = ?")->execute([$id]);
                            $logger->log($_SESSION['user_id'], 'DELETE_DEPT', "Deleted dept: $name");
                        } elseif ($type === 'section') {
                            $pdo->prepare("DELETE FROM sections WHERE id = ?")->execute([$id]);
                            $logger->log($_SESSION['user_id'], 'DELETE_SECTION', "Deleted section: $name");
                        } elseif ($type === 'group') {
                            $pdo->prepare("DELETE FROM groups WHERE id = ?")->execute([$id]);
                            $logger->log($_SESSION['user_id'], 'DELETE_GROUP', "Deleted group: $name");
                        }
                        header("Location: manage_hierarchy.php?msg=" . urlencode("Deleted successfully."));
                        exit;
                    }
                }
            }
        } catch (Exception $e) {
            // [FIX] Never fatal on this page; show a friendly alert instead.
            error_log('manage_hierarchy action failed: ' . $e->getMessage());
            $error = "Action failed: " . $e->getMessage();
        }
    }
}

// [FIX] Load each table independently: one missing/broken table must not
// take down the other two (and never the page, or the navbar JS with it).
$dataErrors = [];

$depts = [];
try {
    $depts = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    $dataErrors[] = "Departments: " . $e->getMessage();
}

$sections = [];
try {
    $sections = $pdo->query("SELECT s.*, d.name as dept_name FROM sections s LEFT JOIN departments d ON s.department_id = d.id ORDER BY d.name, s.name ASC")->fetchAll();
} catch (Exception $e) {
    $dataErrors[] = "Sections: " . $e->getMessage();
}

$groups = [];
try {
    $groups = $pdo->query("SELECT * FROM groups ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    $dataErrors[] = "Groups: " . $e->getMessage();
}

// [FIX] Normalize rows so rendering can't TypeError on an unexpected row shape.
$deptsRows = [];
foreach (is_array($depts) ? $depts : [] as $row) {
    $row = is_array($row) ? $row : (is_object($row) ? get_object_vars($row) : ['name' => $row]);
    $deptsRows[] = [
        'id' => (int)($row['id'] ?? 0),
        'name' => hierarchyRowName($row),
    ];
}

$sectionsRows = [];
foreach (is_array($sections) ? $sections : [] as $row) {
    $row = is_array($row) ? $row : (is_object($row) ? get_object_vars($row) : ['name' => $row]);
    $deptName = isset($row['dept_name']) && is_scalar($row['dept_name']) ? (string)$row['dept_name'] : '';
    $sectionsRows[] = [
        'id' => (int)($row['id'] ?? 0),
        'name' => hierarchyRowName($row),
        'dept_name' => $deptName !== '' ? $deptName : (isset($row['department_id']) ? ('Dept #' . $row['department_id']) : ''),
    ];
}

$groupsRows = [];
foreach (is_array($groups) ? $groups : [] as $row) {
    $row = is_array($row) ? $row : (is_object($row) ? get_object_vars($row) : ['name' => $row]);
    $groupsRows[] = [
        'id' => (int)($row['id'] ?? 0),
        'name' => hierarchyRowName($row),
    ];
}

require 'header.php';
?>

<div class="container mt-4">
    <h4 class="mb-4"><i class="bi bi-gear-fill"></i> Manage Organization Hierarchy</h4>

    <?php if ($msg): ?><div class="alert alert-success"><?php echo h($msg); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>
    <?php if ($dataErrors): ?>
        <div class="alert alert-warning">
            <strong>Some hierarchy data could not be loaded:</strong>
            <?php foreach ($dataErrors as $dataError): ?><div class="small"><?php echo h($dataError); ?></div><?php endforeach; ?>
            <div class="small">If the <code>groups</code> table is missing, run the latest schema migration (see <code>schema/migrations/</code>).</div>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- DEPARTMENTS -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">1. Departments</div>
                <div class="card-body">
                    <form method="POST" class="input-group mb-3">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="type" value="dept">
                        <input type="text" name="name" class="form-control" placeholder="New Dept..." required>
                        <button class="btn btn-success" type="submit">Add</button>
                    </form>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($deptsRows as $d): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo h($d['name']); ?>
                                <?php if ($d['id'] > 0): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                        <button class="btn btn-link text-danger p-0"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($deptsRows) && !in_array('Departments:', $dataErrors, true)): ?>
                            <li class="list-group-item text-muted small">No departments yet.</li>
                        <?php endif; ?>
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
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="type" value="section">
                        <div class="mb-2">
                            <select name="parent" class="form-select" required>
                                <option value="">-- Assign to Dept --</option>
                                <?php foreach ($deptsRows as $d): if ($d['id'] > 0): ?><option value="<?php echo $d['id']; ?>"><?php echo h($d['name']); ?></option><?php endif; endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <input type="text" name="name" class="form-control" placeholder="New Section..." required>
                            <button class="btn btn-success" type="submit">Add</button>
                        </div>
                    </form>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($sectionsRows as $s): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div><small class="text-muted d-block"><?php echo h($s['dept_name']); ?></small> <?php echo h($s['name']); ?></div>
                                <?php if ($s['id'] > 0): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                        <button class="btn btn-link text-danger p-0"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($sectionsRows) && !in_array('Sections:', $dataErrors, true)): ?>
                            <li class="list-group-item text-muted small">No sections yet.</li>
                        <?php endif; ?>
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
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="type" value="group">
                        <input type="text" name="name" class="form-control" placeholder="New Group..." required>
                        <button class="btn btn-success" type="submit">Add</button>
                    </form>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($groupsRows as $g): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo h($g['name']); ?>
                                <?php if ($g['id'] > 0): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="id" value="<?php echo $g['id']; ?>">
                                        <button class="btn btn-link text-danger p-0"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($groupsRows) && !in_array('Groups:', $dataErrors, true)): ?>
                            <li class="list-group-item text-muted small">No groups yet.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- [FIX] footer.php loads assets/bootstrap.bundle.min.js exactly once.
     Do NOT add a second copy here: a double-loaded Bootstrap 5 data API
     registers its click handlers twice and toggles the navbar dropdowns
     open+closed in the same click (they appear dead). -->
<?php require 'footer.php'; ?>
