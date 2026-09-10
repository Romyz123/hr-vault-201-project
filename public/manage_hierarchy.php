<?php
// ======================================================
// [FILE] public/manage_hierarchy.php
// [STATUS] ENHANCED: Bulk Select, Inline Edit Modal & Validation
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

// Helper function to validate names
function validateName(string $name): ?string
{
    $trimmed = trim($name);
    if (empty($trimmed)) {
        return "Name cannot be empty.";
    }
    if (mb_strlen($trimmed) > 100) {
        return "Name is too long (max 100 characters).";
    }
    // Allow A-Z, 0-9, spaces, hyphens, dots, commas, ampersands, and slashes
    if (!preg_match('/^[A-Za-z0-9\s\-\.\,\&\/]+$/', $trimmed)) {
        return "Name contains invalid characters.";
    }
    return null;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $error = "Invalid CSRF token.";
    } else {
        $action = $_POST['action'];
        $type = $_POST['type'] ?? ''; // dept, section, group

        // --- 1. ADD ITEM ---
        if ($action === 'add') {
            $name = strtoupper(trim($_POST['name'] ?? ''));
            $parent = $_POST['parent'] ?? null;

            $valErr = validateName($name);
            if ($valErr) {
                $error = $valErr;
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

            // --- 2. EDIT ITEM ---
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $name = strtoupper(trim($_POST['name'] ?? ''));
            $parent = $_POST['parent'] ?? null;

            $valErr = validateName($name);
            if ($id <= 0) {
                $error = "Invalid ID target.";
            } elseif ($valErr) {
                $error = $valErr;
            } elseif ($type === 'section' && empty($parent)) {
                $error = "Please select a department for the section.";
            }

            if (empty($error)) {
                try {
                    if ($type === 'dept') {
                        $stmt = $pdo->prepare("UPDATE departments SET name = ? WHERE id = ?");
                        $stmt->execute([$name, $id]);
                        $logger->log($_SESSION['user_id'], 'EDIT_DEPT', "Updated dept ID $id to $name");
                    } elseif ($type === 'section') {
                        $stmt = $pdo->prepare("UPDATE sections SET department_id = ?, name = ? WHERE id = ?");
                        $stmt->execute([(int)$parent, $name, $id]);
                        $logger->log($_SESSION['user_id'], 'EDIT_SECTION', "Updated section ID $id to $name");
                    } elseif ($type === 'group') {
                        $stmt = $pdo->prepare("UPDATE groups SET name = ? WHERE id = ?");
                        $stmt->execute([$name, $id]);
                        $logger->log($_SESSION['user_id'], 'EDIT_GROUP', "Updated group ID $id to $name");
                    }
                    header("Location: manage_hierarchy.php?msg=" . urlencode("Updated successfully."));
                    exit;
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            }

            // --- 3. DELETE (SINGLE OR BULK) ---
        } elseif ($action === 'delete') {
            $ids = [];
            if (isset($_POST['ids']) && is_array($_POST['ids'])) {
                $ids = array_map('intval', $_POST['ids']);
            } elseif (isset($_POST['id'])) {
                $ids[] = (int)$_POST['id'];
            }

            if (empty($ids)) {
                $error = "No items selected for deletion.";
            } else {
                $deletedCount = 0;
                $blockedItems = [];

                foreach ($ids as $id) {
                    $name = '';
                    $targetTable = '';
                    $empColumn = '';

                    if ($type === 'dept') {
                        $targetTable = 'departments';
                        $empColumn = 'dept';
                    } elseif ($type === 'section') {
                        $targetTable = 'sections';
                        $empColumn = 'section';
                    } else {
                        $targetTable = 'groups';
                        $empColumn = '`group`';
                        $type = 'group';
                    }

                    // Fetch Name
                    $stmt = $pdo->prepare("SELECT name FROM $targetTable WHERE id = ?");
                    $stmt->execute([$id]);
                    $name = $stmt->fetchColumn();

                    if ($name) {
                        // Check Employee Table Dependencies
                        $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE $empColumn = ? OR $empColumn LIKE ? OR $empColumn LIKE ? OR $empColumn LIKE ?");
                        $chk->execute([$name, "$name, %", "%, $name", "%, $name, %"]);

                        if ($chk && $chk->fetchColumn() > 0) {
                            $blockedItems[] = $name;
                        } else {
                            $pdo->prepare("DELETE FROM $targetTable WHERE id = ?")->execute([$id]);
                            $logger->log($_SESSION['user_id'], 'DELETE_' . strtoupper($type), "Deleted $type: $name");
                            $deletedCount++;
                        }
                    }
                }

                if (!empty($blockedItems)) {
                    $error = "Could not delete: " . implode(', ', $blockedItems) . " (assigned to active employees).";
                }
                if ($deletedCount > 0) {
                    $msg = "Successfully deleted $deletedCount item(s). " . ($error ? "Some items were skipped." : "");
                    header("Location: manage_hierarchy.php?msg=" . urlencode($msg) . ($error ? "&error=" . urlencode($error) : ""));
                    exit;
                }
            }
        }
    }
}

// Fetch Organizational Records
$orgDepts = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();
$orgSections = $pdo->query("SELECT s.*, d.name as dept_name FROM sections s JOIN departments d ON s.department_id = d.id ORDER BY d.name, s.name ASC")->fetchAll();
$orgGroups = $pdo->query("SELECT * FROM groups ORDER BY name ASC")->fetchAll();

require 'header.php';
?>

<div class="container mt-4">
    <h4 class="mb-4"><i class="bi bi-gear-fill"></i> Manage Organization Hierarchy</h4>

    <?php if ($msg): ?><div class="alert alert-success alert-dismissible fade show"><?php echo h($msg); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo h($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <div class="row">
        <!-- ================= DEPARTMENTS COLUMN ================= -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold">1. Departments</span>
                    <span class="badge bg-light text-dark"><?php echo count($orgDepts); ?></span>
                </div>
                <div class="card-body">
                    <!-- Add Form -->
                    <form method="POST" class="input-group mb-3">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="type" value="dept">
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                        <input type="text" name="name" class="form-control" placeholder="New Dept..." maxlength="100" pattern="[A-Za-z0-9\s\-\.\,\&amp;\/]+" title="Allowed: letters, numbers, spaces, and - . , & /" required>
                        <button class="btn btn-success" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
                    </form>

                    <!-- Selection & Bulk Controls -->
                    <form method="POST" id="bulkDeptForm" onsubmit="return confirm('Delete selected departments?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="type" value="dept">
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">

                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleCheckboxes('deptCheck', true)">Select All</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleCheckboxes('deptCheck', false)">Deselect All</button>
                            </div>
                            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> Delete Selected</button>
                        </div>

                        <!-- List Items -->
                        <ul class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                            <?php foreach ($orgDepts as $d): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-1">
                                    <div class="form-check text-truncate me-2">
                                        <input class="form-check-input deptCheck" type="checkbox" name="ids[]" value="<?php echo $d['id']; ?>">
                                        <label class="form-check-label fw-semibold text-dark">
                                            <?php echo h($d['name']); ?>
                                        </label>
                                    </div>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-link text-primary p-0 me-2" onclick="openEditModal('dept', <?php echo $d['id']; ?>, '<?php echo addslashes(h($d['name'])); ?>')">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </form>
                </div>
            </div>
        </div>

        <!-- ================= SECTIONS COLUMN ================= -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold">2. Sections</span>
                    <span class="badge bg-light text-dark"><?php echo count($orgSections); ?></span>
                </div>
                <div class="card-body">
                    <!-- Add Form -->
                    <form method="POST" class="mb-3">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="type" value="section">
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                        <div class="mb-2">
                            <select name="parent" class="form-select" required>
                                <option value="">-- Select Parent Dept --</option>
                                <?php foreach ($orgDepts as $d): ?>
                                    <option value="<?php echo $d['id']; ?>"><?php echo h($d['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <input type="text" name="name" class="form-control" placeholder="New Section..." maxlength="100" pattern="[A-Za-z0-9\s\-\.\,\&amp;\/]+" title="Allowed: letters, numbers, spaces, and - . , & /" required>
                            <button class="btn btn-success" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
                        </div>
                    </form>

                    <!-- Selection & Bulk Controls -->
                    <form method="POST" id="bulkSectionForm" onsubmit="return confirm('Delete selected sections?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="type" value="section">
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">

                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleCheckboxes('sectionCheck', true)">Select All</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleCheckboxes('sectionCheck', false)">Deselect All</button>
                            </div>
                            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> Delete Selected</button>
                        </div>

                        <!-- List Items -->
                        <ul class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                            <?php foreach ($orgSections as $s): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-1">
                                    <div class="form-check text-truncate me-2">
                                        <input class="form-check-input sectionCheck" type="checkbox" name="ids[]" value="<?php echo $s['id']; ?>">
                                        <label class="form-check-label">
                                            <small class="text-muted d-block"><?php echo h($s['dept_name']); ?></small>
                                            <span class="fw-semibold text-dark"><?php echo h($s['name']); ?></span>
                                        </label>
                                    </div>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-link text-primary p-0 me-2" onclick="openEditModal('section', <?php echo $s['id']; ?>, '<?php echo addslashes(h($s['name'])); ?>', <?php echo $s['department_id']; ?>)">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </form>
                </div>
            </div>
        </div>

        <!-- ================= GROUPS COLUMN ================= -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold">3. Groups</span>
                    <span class="badge bg-light text-dark"><?php echo count($orgGroups); ?></span>
                </div>
                <div class="card-body">
                    <!-- Add Form -->
                    <form method="POST" class="input-group mb-3">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="type" value="group">
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                        <input type="text" name="name" class="form-control" placeholder="New Group..." maxlength="100" pattern="[A-Za-z0-9\s\-\.\,\&amp;\/]+" title="Allowed: letters, numbers, spaces, and - . , & /" required>
                        <button class="btn btn-success" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
                    </form>

                    <!-- Selection & Bulk Controls -->
                    <form method="POST" id="bulkGroupForm" onsubmit="return confirm('Delete selected groups?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="type" value="group">
                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">

                        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleCheckboxes('groupCheck', true)">Select All</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleCheckboxes('groupCheck', false)">Deselect All</button>
                            </div>
                            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> Delete Selected</button>
                        </div>

                        <!-- List Items -->
                        <ul class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                            <?php foreach ($orgGroups as $g): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-1">
                                    <div class="form-check text-truncate me-2">
                                        <input class="form-check-input groupCheck" type="checkbox" name="ids[]" value="<?php echo $g['id']; ?>">
                                        <label class="form-check-label fw-semibold text-dark">
                                            <?php echo h($g['name']); ?>
                                        </label>
                                    </div>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-link text-primary p-0 me-2" onclick="openEditModal('group', <?php echo $g['id']; ?>, '<?php echo addslashes(h($g['name'])); ?>')">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ================= EDIT MODAL ================= -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="editModalTitle"><i class="bi bi-pencil-square"></i> Edit Entry</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="type" id="editType">
                    <input type="hidden" name="id" id="editId">

                    <div class="mb-3" id="editParentContainer" style="display:none;">
                        <label class="form-label fw-bold">Parent Department</label>
                        <select name="parent" id="editParent" class="form-select">
                            <option value="">-- Select Parent Dept --</option>
                            <?php foreach ($orgDepts as $d): ?>
                                <option value="<?php echo $d['id']; ?>"><?php echo h($d['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Name</label>
                        <input type="text" name="name" id="editName" class="form-control" maxlength="100" pattern="[A-Za-z0-9\s\-\.\,\&amp;\/]+" title="Allowed: letters, numbers, spaces, and - . , & /" required>
                        <small class="form-text text-muted">Maximum 100 characters.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Toggle all checkboxes in a group
    function toggleCheckboxes(className, checkState) {
        const checkboxes = document.querySelectorAll('.' + className);
        checkboxes.forEach(cb => cb.checked = checkState);
    }

    // Open and configure dynamic Edit Modal
    function openEditModal(type, id, name, parentId = null) {
        document.getElementById('editType').value = type;
        document.getElementById('editId').value = id;
        document.getElementById('editName').value = name;

        const parentContainer = document.getElementById('editParentContainer');
        const parentSelect = document.getElementById('editParent');

        if (type === 'section') {
            parentContainer.style.display = 'block';
            parentSelect.required = true;
            parentSelect.value = parentId || '';
        } else {
            parentContainer.style.display = 'none';
            parentSelect.required = false;
            parentSelect.value = '';
        }

        document.getElementById('editModalTitle').innerText = 'Edit ' + type.charAt(0).toUpperCase() + type.slice(1);

        const modal = new bootstrap.Modal(document.getElementById('editModal'));
        modal.show();
    }
</script>

<?php require 'footer.php'; ?>