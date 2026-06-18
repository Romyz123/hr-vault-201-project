<?php
// ======================================================
// [FILE] public/manage_hierarchy.php
// [STATUS] NEW: Separate Management for Dept, Section, Group
// ======================================================

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

// Handle Addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $error = "Invalid CSRF token.";
    } else {
        $action = $_POST['action'];
        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? ''; // dept, section, group
        $parent = $_POST['parent'] ?? NULL; // For sections

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
                try {
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
                    }
                    header("Location: manage_hierarchy.php?msg=" . urlencode("Added successfully."));
                    exit;
                } catch (Exception $e) {
                    $error = $e->getMessage();
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
                $name = $rowName;
            }

            if (!$name) {
                $stmt = $pdo->prepare("SELECT name FROM sections WHERE id = ?");
                $stmt->execute([$id]);
                $rowName = $stmt->fetchColumn();
                if ($rowName !== false) {
                    $type = 'section';
                    $name = $rowName;
                }
            }

            if (!$name) {
                $stmt = $pdo->prepare("SELECT name FROM groups WHERE id = ?");
                $stmt->execute([$id]);
                $rowName = $stmt->fetchColumn();
                if ($rowName !== false) {
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
    }
}

$depts = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
$sections = $pdo->query("SELECT s.*, d.name as dept_name FROM sections s JOIN departments d ON s.department_id = d.id ORDER BY d.name, s.name ASC")->fetchAll();
$groups = $pdo->query("SELECT * FROM groups ORDER BY name ASC")->fetchAll();

require 'header.php';
?>

<div class="container mt-4">
    <h4 class="mb-4"><i class="bi bi-gear-fill"></i> Manage Organization Hierarchy</h4>

    <?php if ($msg): ?><div class="alert alert-success"><?php echo h($msg); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endif; ?>

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
                        <?php foreach ($depts as $d): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo h($d['name']); ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
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
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="type" value="section">
                        <div class="mb-2">
                            <select name="parent" class="form-select" required>
                                <option value="">-- Assign to Dept --</option>
                                <?php foreach ($depts as $d): ?><option value="<?php echo $d['id']; ?>"><?php echo h($d['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <input type="text" name="name" class="form-control" placeholder="New Section..." required>
                            <button class="btn btn-success" type="submit">Add</button>
                        </div>
                    </form>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($sections as $s): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div><small class="text-muted d-block"><?php echo h($s['dept_name']); ?></small> <?php echo h($s['name']); ?></div>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
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
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="type" value="group">
                        <input type="text" name="name" class="form-control" placeholder="New Group..." required>
                        <button class="btn btn-success" type="submit">Add</button>
                    </form>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($groups as $g): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <?php echo h($g['name']); ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="id" value="<?php echo $g['id']; ?>">
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

<?php require 'footer.php'; ?>