<?php
// ======================================================
// [FILE] public/bulk_update_roles.php
// [PURPOSE] Bulk update System Roles, Job Titles, Departments, and Groups
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/Validator.php';
require '../src/SearchHelper.php';

if (!function_exists('checkSessionTimeout')) {
    require_once __DIR__ . '/../config/db.php';
}
if (!function_exists('h')) {
    require_once __DIR__ . '/../src/helpers.php';
}
session_start();
checkSessionTimeout($pdo);

$clientTimeout = 900;
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
    $val = $stmt->fetchColumn();
    if ($val) $clientTimeout = (int)$val;
} catch (Exception $e) {
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    header("Location: index.php");
    exit;
}

$logger = new Logger($pdo);
$msg = "";
$error = "";
$agencies = [];
$deptMap = [];

$groups = [];
$system_roles = [];
$dryRunResults = null;
$bulkFields = [
    'system_role' => 'Role',
    'job_title' => 'Job Title',
    'dept' => 'Department',
    'section' => 'Section',
    'group_name' => 'Group',
    'gender' => 'Gender',
    'agency_name' => 'Agency',
    'employment_type' => 'Employment Type',
    'status' => 'Status'
];

function bulkFilteredEmployeeIds(PDO $pdo, array $filters): array
{
    $where = ['deleted_at IS NULL'];
    $params = [];
    $search = Validator::sanitizeSearch($filters['search'] ?? '');
    if ($search !== '') {
        $where[] = '(emp_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
        $term = "%$search%";
        array_push($params, $term, $term, $term);
    }
    $filterMap = ['dept' => 'dept', 'section' => 'section', 'job_title' => 'job_title', 'role' => 'system_role', 'status' => 'status', 'agency' => 'agency_name'];
    foreach ($filterMap as $input => $column) {
        if (($filters[$input] ?? '') !== '') {
            $where[] = "$column = ?";
            $params[] = $filters[$input];
        }
    }
    $stmt = $pdo->prepare('SELECT id FROM employees WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function bulkCreateBatch(PDO $pdo, int $userId, string $reason, string $source, string $status): int
{
    $stmt = $pdo->prepare('INSERT INTO bulk_update_batches (batch_uuid, requested_by, reason, source, status) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([sprintf('%s-%s-%s-%s', uniqid('', true), bin2hex(random_bytes(4)), date('YmdHis'), $userId), $userId, $reason ?: null, $source, $status]);
    return (int)$pdo->lastInsertId();
}

function bulkHasSensitiveChanges(array $changes): bool
{
    foreach ($changes as $change) {
        if ($change['field_name'] === 'system_role' || $change['field_name'] === 'status') return true;
    }
    return false;
}

require __DIR__ . '/options.php';

try {
    $dbGroups = $pdo->query("SELECT group_name FROM org_groups ORDER BY group_name ASC")->fetchAll(PDO::FETCH_COLUMN);
    $groups = !empty($dbGroups) ? $dbGroups : ($org_groups ?? []);
} catch (Exception $e) {
    $groups = $org_groups ?? [];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// APPROVE, REJECT, OR ROLLBACK A BULK BATCH
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_action'])) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Error: Invalid Token.");
    }

    $batchId = (int)($_POST['batch_id'] ?? 0);
    $batchAction = $_POST['batch_action'];
    if ($batchId <= 0 || !in_array($batchAction, ['approve', 'reject', 'rollback'], true)) {
        die('Invalid bulk batch request.');
    }

    if ($batchAction !== 'rollback' && strtoupper((string)($_SESSION['role'] ?? '')) !== 'ADMIN') {
        die('Only an administrator can approve or reject bulk changes.');
    }

    try {
        $pdo->beginTransaction();
        $batchStmt = $pdo->prepare('SELECT * FROM bulk_update_batches WHERE id = ? FOR UPDATE');
        $batchStmt->execute([$batchId]);
        $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
        if (!$batch) {
            throw new RuntimeException('Bulk batch not found.');
        }

        $changesStmt = $pdo->prepare('SELECT employee_id, field_name, old_value, new_value FROM bulk_update_changes WHERE batch_id = ? ORDER BY id');
        $changesStmt->execute([$batchId]);
        $changes = $changesStmt->fetchAll(PDO::FETCH_ASSOC);
        $allowedBatchFields = ['system_role', 'job_title', 'dept', 'section', 'group_name', 'gender', 'agency_name', 'employment_type', 'status'];

        if ($batchAction === 'approve') {
            if ($batch['status'] !== 'PENDING_APPROVAL') throw new RuntimeException('This batch is no longer awaiting approval.');
            foreach ($changes as $change) {
                if (!in_array($change['field_name'], $allowedBatchFields, true)) throw new RuntimeException('Invalid field in bulk batch.');
                $stmt = $pdo->prepare("UPDATE employees SET `{$change['field_name']}` = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$change['new_value'], $change['employee_id']]);
            }
            $pdo->prepare("UPDATE bulk_update_batches SET status = 'APPROVED', approved_by = ?, approved_at = NOW() WHERE id = ?")
                ->execute([$_SESSION['user_id'], $batchId]);
            $pdo->prepare("INSERT INTO bulk_update_approvals (batch_id, reviewer_id, decision, review_note) VALUES (?, ?, 'APPROVED', ?)")
                ->execute([$batchId, $_SESSION['user_id'], trim($_POST['review_note'] ?? '') ?: null]);
            $logger->log($_SESSION['user_id'], 'BULK_UPDATE_APPROVED', "Approved bulk update batch $batchId.");
            $msg = 'Bulk update approved and applied.';
        } elseif ($batchAction === 'reject') {
            if ($batch['status'] !== 'PENDING_APPROVAL') throw new RuntimeException('This batch is no longer awaiting approval.');
            $pdo->prepare("UPDATE bulk_update_batches SET status = 'REJECTED', approved_by = ?, approved_at = NOW() WHERE id = ?")
                ->execute([$_SESSION['user_id'], $batchId]);
            $pdo->prepare("INSERT INTO bulk_update_approvals (batch_id, reviewer_id, decision, review_note) VALUES (?, ?, 'REJECTED', ?)")
                ->execute([$batchId, $_SESSION['user_id'], trim($_POST['review_note'] ?? '') ?: null]);
            $logger->log($_SESSION['user_id'], 'BULK_UPDATE_REJECTED', "Rejected bulk update batch $batchId.");
            $msg = 'Bulk update rejected.';
        } else {
            if (!in_array($batch['status'], ['APPLIED', 'APPROVED'], true)) throw new RuntimeException('Only applied batches can be rolled back.');
            foreach ($changes as $change) {
                if (!in_array($change['field_name'], $allowedBatchFields, true)) throw new RuntimeException('Invalid field in bulk batch.');
                $currentStmt = $pdo->prepare("SELECT `{$change['field_name']}` FROM employees WHERE id = ? AND deleted_at IS NULL");
                $currentStmt->execute([$change['employee_id']]);
                $current = $currentStmt->fetchColumn();
                if ((string)$current === (string)$change['new_value']) {
                    $stmt = $pdo->prepare("UPDATE employees SET `{$change['field_name']}` = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$change['old_value'], $change['employee_id']]);
                }
            }
            $pdo->prepare("UPDATE bulk_update_batches SET status = 'ROLLED_BACK', rolled_back_by = ?, rolled_back_at = NOW() WHERE id = ?")
                ->execute([$_SESSION['user_id'], $batchId]);
            $logger->log($_SESSION['user_id'], 'BULK_UPDATE_ROLLBACK', "Rolled back bulk update batch $batchId.");
            $msg = 'Bulk update rolled back where records were unchanged.';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// 2. HANDLE BULK UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_roles']) || isset($_POST['dry_run']))) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Error: Invalid Token.");
    }

    $ids = $_POST['employee_ids'] ?? [];
    if (!empty($_POST['select_all_filtered'])) {
        $ids = bulkFilteredEmployeeIds($pdo, [
            'search' => $_POST['search_state'] ?? '',
            'dept' => $_POST['dept_state'] ?? '',
            'section' => $_POST['section_state'] ?? '',
            'job_title' => $_POST['job_title_state'] ?? '',
            'role' => $_POST['role_state'] ?? '',
            'status' => $_POST['status_state'] ?? '',
            'agency' => $_POST['agency_state'] ?? '',
            'dup_filter' => $_POST['dup_filter_state'] ?? 'all'
        ]);
    }
    $ids = array_filter($ids, function ($id) {
        return is_numeric($id);
    });
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $filterIds = bulkFilteredEmployeeIds($pdo, [
        'search' => $_POST['search_state'] ?? '',
        'dept' => $_POST['dept_state'] ?? '',
        'section' => $_POST['section_state'] ?? '',
        'job_title' => $_POST['job_title_state'] ?? '',
        'role' => $_POST['role_state'] ?? '',
        'status' => $_POST['status_state'] ?? '',
        'agency' => $_POST['agency_state'] ?? '',
        'dup_filter' => $_POST['dup_filter_state'] ?? 'all'
    ]);
    $ids = array_values(array_intersect($ids, $filterIds));

    $new_role     = trim($_POST['new_system_role'] ?? '');
    $new_job      = trim($_POST['new_job_title'] ?? '');
    $new_dept     = trim($_POST['new_dept'] ?? '');
    $new_section  = trim($_POST['new_section'] ?? '');
    $new_group    = trim($_POST['new_group'] ?? '');
    $new_gender   = trim($_POST['new_gender'] ?? '');
    $new_agency   = trim($_POST['new_agency'] ?? '');
    $new_status   = trim($_POST['new_status'] ?? '');
    $new_emp_type = trim($_POST['new_emp_type'] ?? '');
    $audit_reason = substr(trim($_POST['audit_reason'] ?? ''), 0, 200);
    $clearFields = [];
    foreach (array_keys($bulkFields) as $field) {
        if (!empty($_POST['clear_' . $field])) $clearFields[$field] = true;
    }
    if (!empty($clearFields['status'])) {
        $error = 'Employee status cannot be cleared. Choose a configured status instead.';
    }

    $allowedRoles = $system_roles;
    $allowedGenders = ['Male', 'Female', 'Other'];
    $allowedDepts = array_keys($deptMap);

    if (!empty($new_role) && !in_array($new_role, $allowedRoles)) {
        $error = "❌ Invalid role selected.";
    } elseif (!empty($new_gender) && !in_array($new_gender, $allowedGenders)) {
        $error = "❌ Invalid gender selected.";
    } elseif (!empty($new_status) && !in_array($new_status, $employee_statuses, true)) {
        $error = "❌ Invalid status selected.";
    } elseif (!empty($new_dept)) {
        $selectedDepts = array_map('trim', explode(',', $new_dept));
        foreach ($selectedDepts as $sd) {
            if (!in_array($sd, $allowedDepts)) {
                $error = "❌ Invalid department selected: " . htmlspecialchars($sd);
                break;
            }
        }
    }

    if (empty($ids)) {
        $error = "❌ No employees selected.";
    } else {
        $valid = empty($error);
        if (($new_role !== '' || $new_status !== '') && strtoupper((string)($_SESSION['role'] ?? '')) !== 'ADMIN') {
            $error = "Sensitive role or employment-status changes require administrator approval.";
            $valid = false;
        }
        if ($new_job !== '') {
            if (strlen($new_job) > 50) {
                $error = "❌ Job Title is too long (Max 50 chars).";
                $valid = false;
            } elseif (!preg_match('/^[a-zA-Z0-9\s\-\.\,\(\)\/]+$/', $new_job)) {
                $error = "❌ Job Title contains invalid characters.";
                $valid = false;
            }
        }

        if ($valid) {
            if (isset($_POST['dry_run'])) {
                $placeholders = rtrim(str_repeat('?,', count($ids)), ',');
                $sql = "SELECT id, emp_id, first_name, last_name, job_title, dept, section, group_name, gender, system_role, agency_name, employment_type, status FROM employees WHERE id IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($ids);
                $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $dryRunResults = [];
                foreach ($targets as $t) {
                    $changes = [];
                    $oldRole    = htmlspecialchars($t['system_role'] ?? '', ENT_QUOTES, 'UTF-8');
                    $oldJob     = htmlspecialchars($t['job_title'] ?? '', ENT_QUOTES, 'UTF-8');
                    $oldDept    = htmlspecialchars($t['dept'] ?? '', ENT_QUOTES, 'UTF-8');
                    $oldSection = htmlspecialchars($t['section'] ?? '', ENT_QUOTES, 'UTF-8');
                    $oldGroup   = htmlspecialchars($t['group_name'] ?? '', ENT_QUOTES, 'UTF-8');
                    $oldGender  = htmlspecialchars($t['gender'] ?? '', ENT_QUOTES, 'UTF-8');
                    $oldAgency  = htmlspecialchars($t['agency_name'] ?? '', ENT_QUOTES, 'UTF-8');
                    $oldEmpType = htmlspecialchars($t['employment_type'] ?? '', ENT_QUOTES, 'UTF-8');
                    $oldStatus  = htmlspecialchars($t['status'] ?? '', ENT_QUOTES, 'UTF-8');

                    if ($new_role && ($t['system_role'] ?? '') !== $new_role) {
                        $changes[] = "Role: <s>$oldRole</s> &rarr; <strong>" . htmlspecialchars($new_role, ENT_QUOTES, 'UTF-8') . "</strong>";
                    }
                    if ($new_job) {
                        $fmtJob = ucwords(strtolower($new_job));
                        if (($t['job_title'] ?? '') !== $fmtJob) {
                            $changes[] = "Job: <s>$oldJob</s> &rarr; <strong>" . htmlspecialchars($fmtJob, ENT_QUOTES, 'UTF-8') . "</strong>";
                        }
                    }
                    if ($new_dept && ($t['dept'] ?? '') !== $new_dept) {
                        $changes[] = "Dept: <s>$oldDept</s> &rarr; <strong>" . htmlspecialchars($new_dept, ENT_QUOTES, 'UTF-8') . "</strong>";
                    }
                    if ($new_section && ($t['section'] ?? '') !== $new_section) {
                        $changes[] = "Section: <s>$oldSection</s> &rarr; <strong>" . htmlspecialchars($new_section, ENT_QUOTES, 'UTF-8') . "</strong>";
                    }
                    if ($new_group && ($t['group_name'] ?? '') !== $new_group) {
                        $changes[] = "Group: <s>$oldGroup</s> &rarr; <strong>" . htmlspecialchars($new_group, ENT_QUOTES, 'UTF-8') . "</strong>";
                    }
                    if ($new_gender && ($t['gender'] ?? '') !== $new_gender) {
                        $changes[] = "Gender: <s>$oldGender</s> &rarr; <strong>" . htmlspecialchars($new_gender, ENT_QUOTES, 'UTF-8') . "</strong>";
                    }
                    if ($new_status && ($t['status'] ?? '') !== $new_status) {
                        $changes[] = "Status: <s>$oldStatus</s> &rarr; <strong class='text-danger'>" . htmlspecialchars($new_status, ENT_QUOTES, 'UTF-8') . "</strong>";
                    }
                    if ($new_agency) {
                        $newEmpType = (stripos($new_agency, 'TESP') !== false) ? 'TESP Direct' : 'Agency';
                        if (($t['agency_name'] ?? '') !== $new_agency) {
                            $changes[] = "Agency: <s>$oldAgency</s> &rarr; <strong>" . htmlspecialchars($new_agency, ENT_QUOTES, 'UTF-8') . "</strong>";
                        }
                        if ($oldEmpType !== $newEmpType) {
                            $changes[] = "Type: <s>$oldEmpType</s> &rarr; <strong>$newEmpType</strong>";
                        }
                    }
                    if ($new_emp_type && ($t['employment_type'] ?? '') !== $new_emp_type) {
                        $changes[] = "Employment Type: <s>$oldEmpType</s> &rarr; <strong>" . htmlspecialchars($new_emp_type, ENT_QUOTES, 'UTF-8') . "</strong>";
                    }

                    if (!empty($changes)) {
                        $dryRunResults[] = [
                            'name'    => $t['first_name'] . ' ' . $t['last_name'],
                            'dept'    => $t['dept'] ?? '',
                            'section' => $t['section'] ?? '',
                            'changes' => $changes
                        ];
                    }
                }
            } else {
                try {
                    $pdo->beginTransaction();

                    $snapshotStmt = $pdo->prepare("SELECT id, system_role, job_title, dept, section, group_name, gender, agency_name, employment_type, status FROM employees WHERE deleted_at IS NULL AND id IN (" . rtrim(str_repeat('?,', count($ids)), ',') . ") FOR UPDATE");
                    $snapshotStmt->execute($ids);
                    $snapshots = [];
                    foreach ($snapshotStmt->fetchAll(PDO::FETCH_ASSOC) as $snapshot) $snapshots[(int)$snapshot['id']] = $snapshot;
                    $sql = "UPDATE employees SET ";
                    $params = [];
                    $updates = [];

                    if (!empty($new_role)) {
                        $updates[] = "system_role = ?";
                        $params[] = $new_role;
                    }
                    if ($new_job !== '') {
                        $updates[] = "job_title = ?";
                        $params[] = ucwords(strtolower($new_job));
                    }
                    if (!empty($new_dept)) {
                        $updates[] = "dept = ?";
                        $params[] = $new_dept;
                    }
                    if (!empty($new_section)) {
                        $updates[] = "section = ?";
                        $params[] = $new_section;
                    }
                    if (!empty($new_group)) {
                        $updates[] = "group_name = ?";
                        $params[] = $new_group;
                    }
                    if (!empty($new_gender)) {
                        $updates[] = "gender = ?";
                        $params[] = $new_gender;
                    }
                    if (!empty($new_status)) {
                        $updates[] = "status = ?";
                        $params[] = $new_status;
                    }
                    if (!empty($new_emp_type)) {
                        $updates[] = "employment_type = ?";
                        $params[] = $new_emp_type;
                    }
                    if (!empty($new_agency)) {
                        $updates[] = "agency_name = ?";
                        $params[] = $new_agency;
                        if (empty($new_emp_type)) { // only auto-set if not manually overridden
                            $updates[] = "employment_type = ?";
                            $params[] = (stripos($new_agency, 'TESP') !== false) ? 'TESP Direct' : 'Agency';
                        }
                    }
                    foreach ($clearFields as $field => $enabled) {
                        if ($enabled && !array_key_exists($field, ['system_role' => 1, 'job_title' => 1, 'dept' => 1, 'section' => 1, 'group_name' => 1, 'gender' => 1, 'agency_name' => 1, 'employment_type' => 1, 'status' => 1])) continue;
                        if ($enabled && empty($updates)) {
                            $updates[] = "$field = NULL";
                        } elseif ($enabled) {
                            $updates[] = "$field = NULL";
                        }
                    }

                    if (empty($updates)) {
                        $error = "⚠️ No changes specified. Please select a field to update.";
                        $pdo->rollBack();
                    } else {
                        $updates[] = "updated_at = NOW()";
                        $sql .= implode(", ", $updates);
                        $sql .= " WHERE id = ?";

                        $stmt = $pdo->prepare($sql);
                        $count = 0;
                        foreach ($ids as $id) {
                            $execParams = $params;
                            $execParams[] = $id;
                            $stmt->execute($execParams);
                            $count++;
                        }

                        $batchId = bulkCreateBatch($pdo, (int)$_SESSION['user_id'], $audit_reason, 'UI', 'APPLIED');
                        $changeStmt = $pdo->prepare('INSERT INTO bulk_update_changes (batch_id, employee_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)');
                        $newValues = [
                            'system_role' => $new_role,
                            'job_title' => $new_job !== '' ? ucwords(strtolower($new_job)) : '',
                            'dept' => $new_dept,
                            'section' => $new_section,
                            'group_name' => $new_group,
                            'gender' => $new_gender,
                            'agency_name' => $new_agency,
                            'employment_type' => $new_emp_type,
                            'status' => $new_status
                        ];
                        foreach ($snapshots as $id => $snapshot) {
                            foreach ($newValues as $field => $newValue) {
                                $requested = $newValue !== '' || !empty($clearFields[$field]);
                                if (!$requested) continue;
                                $effective = !empty($clearFields[$field]) && $newValue === '' ? null : $newValue;
                                if ((string)($snapshot[$field] ?? '') !== (string)$effective) {
                                    $changeStmt->execute([$batchId, $id, $field, $snapshot[$field] ?? null, $effective]);
                                }
                            }
                        }

                        $pdo->commit();
                        $reasonNote = $audit_reason ? " | Reason: $audit_reason" : '';
                        $logger->log($_SESSION['user_id'], 'BULK_UPDATE_ROLE', "Updated details for $count employees.$reasonNote");

                        // Preserve search filters across redirect
                        $redirectParams = http_build_query([
                            'msg' => "Successfully updated $count employees.",
                            'search' => $_POST['search_state'] ?? '',
                            'dept' => $_POST['dept_state'] ?? '',
                            'section' => $_POST['section_state'] ?? '',
                            'job_title' => $_POST['job_title_state'] ?? '',
                            'role' => $_POST['role_state'] ?? '',
                            'status' => $_POST['status_state'] ?? '',
                            'agency' => $_POST['agency_state'] ?? '',
                            'limit' => $_POST['limit_state'] ?? '100'
                        ]);

                        header("Location: bulk_update_roles.php?" . $redirectParams);
                        exit;
                    }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log('Bulk update error: ' . $e->getMessage());
                    $error = "An internal error occurred while updating roles. Please try again later.";
                }
            }
        }
    }
}

if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// 3. FETCH EMPLOYEES & EXTENDED FILTERS
$search         = Validator::sanitizeSearch($_GET['search'] ?? '');
$dept           = $_GET['dept'] ?? '';
$section_filter = $_GET['section'] ?? '';
$job_title_filter = $_GET['job_title'] ?? '';
$role_filter    = $_GET['role'] ?? '';
$status_filter  = $_GET['status'] ?? '';
$agency_filter  = $_GET['agency'] ?? '';
$limit          = $_GET['limit'] ?? '100';
$dup_filter     = in_array($_GET['dup_filter'] ?? '', ['all', 'duplicates', 'unique']) ? ($_GET['dup_filter'] ?? 'all') : 'all';

$sql = "SELECT id, emp_id, first_name, last_name, job_title, dept, section, group_name, system_role, agency_name, employment_type, status FROM employees WHERE deleted_at IS NULL";
$params = [];

if ($search) {
    $sql .= " AND (emp_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
    $term = "%$search%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if ($dept) {
    $sql .= " AND dept = ?";
    $params[] = $dept;
}

if ($section_filter) {
    $sql .= " AND section = ?";
    $params[] = $section_filter;
}

if ($job_title_filter) {
    $sql .= " AND job_title = ?";
    $params[] = $job_title_filter;
}

if ($role_filter) {
    $sql .= " AND system_role = ?";
    $params[] = $role_filter;
}

if ($status_filter) {
    $sql .= " AND status = ?";
    $params[] = $status_filter;
}

if ($agency_filter) {
    $sql .= " AND agency_name = ?";
    $params[] = $agency_filter;
}

$sql .= " ORDER BY last_name ASC";

if ($limit !== 'all' && is_numeric($limit)) {
    $sql .= " LIMIT " . (int)$limit;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3b. DETECT DUPLICATES (by full name, case-insensitive)
$nameCount = [];
foreach ($employees as $e) {
    $key = strtolower(trim($e['first_name'] . '|' . $e['last_name']));
    $nameCount[$key] = ($nameCount[$key] ?? 0) + 1;
}
$duplicateKeys = array_keys(array_filter($nameCount, fn($c) => $c > 1));

// Apply dup_filter on in-memory result set
if ($dup_filter === 'duplicates') {
    $employees = array_filter(
        $employees,
        fn($e) =>
        in_array(strtolower(trim($e['first_name'] . '|' . $e['last_name'])), $duplicateKeys)
    );
    $employees = array_values($employees);
} elseif ($dup_filter === 'unique') {
    $employees = array_filter(
        $employees,
        fn($e) =>
        !in_array(strtolower(trim($e['first_name'] . '|' . $e['last_name'])), $duplicateKeys)
    );
    $employees = array_values($employees);
}
$totalDuplicates = count($duplicateKeys);

$didYouMean = null;
$didYouMeanLink = "#";
if (empty($employees) && !empty($search)) {
    $closest = SearchHelper::findBestMatch($pdo, $search);
    if ($closest) {
        $didYouMean = $closest;
        $didYouMeanLink = "bulk_update_roles.php?search=" . urlencode($closest);
    }
}

$depts = $pdo->query("SELECT DISTINCT dept FROM employees WHERE dept != '' ORDER BY dept")->fetchAll(PDO::FETCH_COLUMN);
$sections = $pdo->query("SELECT DISTINCT section FROM employees WHERE section != '' ORDER BY section")->fetchAll(PDO::FETCH_COLUMN);
$historyLogs = $pdo->query("SELECT a.*, u.username FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id WHERE action = 'BULK_UPDATE_ROLE' ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$pendingBatches = [];
try {
    $pendingBatches = $pdo->query("SELECT b.id, b.source, b.status, b.reason, b.created_at, u.username FROM bulk_update_batches b LEFT JOIN users u ON b.requested_by = u.id WHERE b.status IN ('PENDING_APPROVAL', 'APPLIED', 'APPROVED') ORDER BY b.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Bulk update workflow migration is not installed: ' . $exception->getMessage());
    $error = 'Bulk update history is unavailable until the bulk workflow database migration is applied.';
}
$job_titles = $pdo->query("SELECT DISTINCT job_title FROM employees WHERE job_title != '' ORDER BY job_title")->fetchAll(PDO::FETCH_COLUMN);

if (isset($_GET['export_csv'])) {
    $exportIds = bulkFilteredEmployeeIds($pdo, [
        'search' => $search,
        'dept' => $dept,
        'section' => $section_filter,
        'job_title' => $job_title_filter,
        'role' => $role_filter,
        'status' => $status_filter,
        'agency' => $agency_filter,
        'dup_filter' => $dup_filter
    ]);
    $exportRows = [];
    if (!empty($exportIds)) {
        $placeholders = rtrim(str_repeat('?,', count($exportIds)), ',');
        $exportStmt = $pdo->prepare("SELECT id, emp_id, first_name, last_name, job_title, dept, section, group_name, system_role, agency_name, employment_type, gender, status FROM employees WHERE deleted_at IS NULL AND id IN ($placeholders) ORDER BY last_name ASC");
        $exportStmt->execute($exportIds);
        $exportRows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $logger->log($_SESSION['user_id'], 'BULK_UPDATE_EXPORT', 'Exported ' . count($exportRows) . ' filtered employee records.');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="employees_bulk_update_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['id', 'emp_id', 'first_name', 'last_name', 'job_title', 'dept', 'section', 'group_name', 'system_role', 'agency_name', 'employment_type', 'gender', 'status']);
    foreach ($exportRows as $row) fputcsv($output, $row);
    fclose($output);
    exit;
}

require 'header.php';
?>

<div class="container">
    <!-- DRY RUN RESULTS ALERT -->
    <?php if ($dryRunResults !== null): ?>
        <div class="alert alert-info border-info shadow-sm mb-4">
            <h5 class="alert-heading"><i class="bi bi-eye"></i> Simulation Results (Dry Run)</h5>
            <p class="mb-2">The following changes <strong>would be applied</strong> if you click "Apply Changes". No data has been modified yet.</p>
            <?php if (empty($dryRunResults)): ?>
                <div class="text-muted fst-italic">No changes detected based on your selection.</div>
            <?php else: ?>
                <div class="table-responsive bg-white border rounded">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Section</th>
                                <th>Proposed Changes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dryRunResults as $res): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($res['name']); ?></td>
                                    <td><?php echo htmlspecialchars($res['dept']); ?></td>
                                    <td><?php echo htmlspecialchars($res['section']); ?></td>
                                    <td><?php echo implode('<br>', $res['changes']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- FILTER TOOLBAR -->
    <div class="card shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-center" id="filterForm">
                <!-- 1. Department Filter -->
                <div class="col-md-2">
                    <select name="dept" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php foreach ($depts as $d): ?>
                            <option value="<?php echo htmlspecialchars($d); ?>" <?php echo ($dept === $d) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <!-- Position / Job Title Filter -->
                <div class="col-md-2">
                    <label class="form-label form-label-sm fw-bold text-muted mb-1">Position / Job Title</label>
                    <select name="job_title" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Positions</option>
                        <?php foreach ($job_titles as $jt): ?>
                            <option value="<?php echo htmlspecialchars($jt); ?>" <?php echo ($job_title_filter === $jt) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($jt); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. Section Filter -->
                <div class="col-md-2">
                    <select name="section" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Sections</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?php echo htmlspecialchars($sec); ?>" <?php echo ($section_filter === $sec) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sec); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 3. System Role Filter -->
                <div class="col-md-2">
                    <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Roles</option>
                        <?php foreach ($system_roles as $r): ?>
                            <option value="<?php echo htmlspecialchars($r); ?>" <?php echo ($role_filter === $r) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 4. Employee Status Filter -->
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <?php foreach ($employee_statuses as $st): ?>
                            <option value="<?php echo htmlspecialchars($st); ?>" <?php echo ($status_filter === $st) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($st); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 5. Agency Filter -->
                <div class="col-md-2">
                    <select name="agency" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Agencies</option>
                        <?php foreach ($agencies as $ag): ?>
                            <option value="<?php echo htmlspecialchars($ag); ?>" <?php echo ($agency_filter === $ag) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ag); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 6. Duplicate Record Filter -->
                <div class="col-md-2">
                    <select name="dup_filter" class="form-select form-select-sm fw-bold text-danger" onchange="this.form.submit()">
                        <option value="all" <?php echo $dup_filter === 'all' ? 'selected' : ''; ?>>All Records</option>
                        <option value="duplicates" <?php echo $dup_filter === 'duplicates' ? 'selected' : ''; ?>>⚠️ Duplicates Only (<?php echo $totalDuplicates; ?>)</option>
                        <option value="unique" <?php echo $dup_filter === 'unique' ? 'selected' : ''; ?>>✔ Unique Only</option>
                    </select>
                </div>

                <!-- 7. Search Input & Clear Button -->
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <input type="text" name="search" class="form-control" placeholder="Search Name or ID..." value="<?php echo htmlspecialchars($search); ?>" maxlength="50" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\-_ ]/g, '')">
                        <?php if ($search || $dept || $section_filter || $role_filter || $status_filter || $agency_filter): ?>
                            <a href="bulk_update_roles.php" class="btn btn-outline-secondary" title="Reset All Filters"><i class="bi bi-x-lg"></i> Clear</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 8. Limit Picker -->
                <div class="col-md-2">
                    <select name="limit" class="form-select form-select-sm fw-bold text-primary" onchange="this.form.submit()">
                        <option value="50" <?php echo ($limit === '50') ? 'selected' : ''; ?>>Show 50</option>
                        <option value="100" <?php echo ($limit === '100') ? 'selected' : ''; ?>>Show 100</option>
                        <option value="250" <?php echo ($limit === '250') ? 'selected' : ''; ?>>Show 250</option>
                        <option value="all" <?php echo ($limit === 'all') ? 'selected' : ''; ?>>Show ALL</option>
                    </select>
                </div>

                <!-- 9. Submit Filter Button -->
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> Apply Filters</button>
                </div>
                <div class="col-md-2">
                    <a class="btn btn-outline-success btn-sm w-100" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['export_csv' => 1]))); ?>">
                        <i class="bi bi-download"></i> Export CSV
                    </a>
                </div>
                <div class="col-md-2">
                    <a class="btn btn-outline-secondary btn-sm w-100" href="bulk_update_import.php">
                        <i class="bi bi-upload"></i> Import CSV
                    </a>
                </div>

                <!-- 10. Status Counters -->
                <div class="col-md-4 text-end d-flex gap-2 justify-content-end align-items-center flex-wrap">
                    <span class="badge bg-dark">Listed: <?php echo count($employees); ?></span>
                    <?php if ($totalDuplicates > 0): ?>
                        <span class="badge bg-danger" title="Duplicate name groups detected">
                            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $totalDuplicates; ?> Dup<?php echo $totalDuplicates > 1 ? 's' : ''; ?>
                        </span>
                        <a href="merge_duplicates.php" class="btn btn-sm btn-outline-danger fw-bold ms-2">
                            <i class="bi bi-tools"></i> Merge Tool
                        </a>
                    <?php endif; ?>
                    <span class="badge bg-primary" id="selectionCounter">Selected: 0</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="selectFilteredBtn">
                        <i class="bi bi-check2-all"></i> Select All Filtered
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($didYouMean): ?>
        <div class="alert alert-info text-center shadow-sm mb-4">
            <i class="bi bi-lightbulb-fill me-2"></i> Did you mean:
            <a href="<?php echo $didYouMeanLink; ?>" class="fw-bold text-dark text-decoration-underline"><?php echo htmlspecialchars($didYouMean); ?></a>?
        </div>
    <?php endif; ?>

    <form method="POST" id="bulkUpdateForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <!-- Hidden Fields to Preserve Filter State across Form Submission -->
        <input type="hidden" name="search_state" value="<?php echo htmlspecialchars($search); ?>">
        <input type="hidden" name="dept_state" value="<?php echo htmlspecialchars($dept); ?>">
        <input type="hidden" name="section_state" value="<?php echo htmlspecialchars($section_filter); ?>">
        <input type="hidden" name="job_title_state" value="<?php echo htmlspecialchars($job_title_filter); ?>">
        <input type="hidden" name="role_state" value="<?php echo htmlspecialchars($role_filter); ?>">
        <input type="hidden" name="status_state" value="<?php echo htmlspecialchars($status_filter); ?>">
        <input type="hidden" name="agency_state" value="<?php echo htmlspecialchars($agency_filter); ?>">
        <input type="hidden" name="dup_filter_state" value="<?php echo htmlspecialchars($dup_filter); ?>">
        <input type="hidden" name="select_all_filtered" id="selectAllFiltered" value="0">
        <input type="hidden" name="limit_state" value="<?php echo htmlspecialchars($limit); ?>">

        <!-- UPDATE PANEL -->
        <div class="card shadow-sm mb-4 border-warning">
            <div class="card-header bg-warning text-dark fw-bold">
                <i class="bi bi-pencil-square"></i> Update Selected Employees
            </div>
            <div class="card-body bg-white">
                <div class="alert alert-warning py-2 small mb-3">
                    <strong>Clear fields:</strong> check a field below only when you intend to remove its current value.
                    <div class="d-flex flex-wrap gap-3 mt-2">
                        <?php foreach ($bulkFields as $field => $label): ?>
                            <?php if ($field === 'status') continue; ?>
                            <label class="form-check form-check-inline mb-0">
                                <input class="form-check-input clear-field" type="checkbox" name="clear_<?php echo htmlspecialchars($field); ?>" value="1">
                                <span class="form-check-label">Clear <?php echo htmlspecialchars($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="row g-3 align-items-end justify-content-center">
                    <div class="col-md-2">
                        <label class="form-label fw-bold">New System Role</label>
                        <select name="new_system_role" class="form-select">
                            <option value="">-- No Change --</option>
                            <?php foreach ($system_roles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role); ?>"><?php echo htmlspecialchars($role); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">New Job Title</label>
                        <input type="text" name="new_job_title" class="form-control" list="job_suggestions" placeholder="Leave blank to keep current" maxlength="50" pattern="[a-zA-Z0-9\s\-\.\,\(\)\/]+" title="Allowed: Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)\/]/g, '')">
                        <datalist id="job_suggestions">
                            <?php
                            $allJobs = $pdo->query("SELECT DISTINCT job_title FROM employees WHERE job_title != '' AND status = 'Active' ORDER BY job_title ASC")->fetchAll(PDO::FETCH_COLUMN);
                            foreach ($allJobs as $j) echo "<option value=\"" . htmlspecialchars($j) . "\">"; ?>
                        </datalist>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold text-danger">New Status</label>
                        <select name="new_status" class="form-select border-danger">
                            <option value="">-- No Change --</option>
                            <?php foreach ($employee_statuses as $statusOption): ?>
                                <option value="<?php echo htmlspecialchars($statusOption); ?>"><?php echo htmlspecialchars($statusOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">New Department(s)</label>
                        <div class="input-group">
                            <input type="text" name="new_dept" id="new_dept" class="form-control bg-white" readonly placeholder="No Change">
                            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('new_dept').value = ''; updateNewSections();"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <select id="deptPicker" class="form-select mt-1 form-select-sm text-muted" onchange="addDept(this.value)">
                            <option value="">+ Add Department...</option>
                            <?php foreach (array_keys($deptMap) as $d): ?>
                                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">New Section(s)</label>
                        <div class="input-group">
                            <input type="text" name="new_section" id="new_section" class="form-control bg-white" readonly placeholder="No Change" maxlength="255">
                            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('new_section').value = ''"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <select id="sectionPicker" class="form-select mt-1 form-select-sm text-muted" onchange="addSection(this.value)">
                            <option value="">+ Add Section...</option>
                        </select>
                    </div>

                    <!-- DYNAMIC SYSTEM GROUP PICKER -->
                    <div class="col-md-2">
                        <label class="form-label fw-bold">New Group(s)</label>
                        <div class="input-group">
                            <input type="text" name="new_group" id="new_group" class="form-control bg-white" readonly placeholder="No Change">
                            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('new_group').value = '';"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <select id="groupPicker" class="form-select mt-1 form-select-sm text-muted" onchange="addGroup(this.value)">
                            <option value="">+ Add Group...</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?php echo htmlspecialchars($g); ?>"><?php echo htmlspecialchars($g); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label fw-bold">New Gender</label>
                        <select name="new_gender" class="form-select">
                            <option value="">-- No Change --</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-bold">New Agency</label>
                        <select name="new_agency" class="form-select">
                            <option value="">-- No Change --</option>
                            <?php foreach ($agencies as $agency): ?>
                                <option value="<?php echo htmlspecialchars($agency); ?>"><?php echo htmlspecialchars($agency); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-grid gap-2 pt-3">
                        <button type="submit" name="dry_run" value="1" class="btn btn-info text-white fw-bold btn-sm" formnovalidate>Simulate</button>
                        <button type="submit" name="update_roles" id="applyBtn" class="btn btn-success fw-bold btn-sm">Apply</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('.emp-check').forEach(c => { c.checked = this.checked; updateCounter(); })"></th>
                            <th>Name</th>
                            <th>ID</th>
                            <th>Dept</th>
                            <th>Section</th>
                            <th>Group</th>
                            <th>Current Job Title</th>
                            <th>Current Agency</th>
                            <th>Current Role</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="10" class="text-center p-4 text-muted">No employees found matching your criteria.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($employees as $e): ?>
                                <tr>
                                    <td><input type="checkbox" name="employee_ids[]" value="<?php echo $e['id']; ?>" class="form-check-input emp-check" onchange="updateCounter()"></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($e['last_name'] . ', ' . $e['first_name']); ?></td>
                                    <td><?php echo htmlspecialchars($e['emp_id']); ?></td>
                                    <td><?php echo htmlspecialchars($e['dept']); ?></td>
                                    <td><?php echo htmlspecialchars($e['section']); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($e['group_name'] ?? 'N/A'); ?></span></td>
                                    <td><?php echo htmlspecialchars($e['job_title']); ?></td>
                                    <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($e['agency_name'] ?: $e['employment_type']); ?></span></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($e['system_role']); ?></span></td>
                                    <td><span class="badge <?php echo $e['status'] === 'Active' ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo htmlspecialchars($e['status']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <?php if (!empty($pendingBatches)): ?>
        <div class="card shadow-sm mt-4 border-warning">
            <div class="card-header bg-warning text-dark fw-bold">Bulk Change Review and Rollback</div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Batch</th>
                            <th>Status</th>
                            <th>Requested By</th>
                            <th>Date</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingBatches as $batch): ?>
                            <tr>
                                <td>#<?php echo (int)$batch['id']; ?> <small class="text-muted"><?php echo htmlspecialchars($batch['source']); ?></small></td>
                                <td><?php echo htmlspecialchars($batch['status']); ?></td>
                                <td><?php echo htmlspecialchars($batch['username'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($batch['created_at']))); ?></td>
                                <td class="text-end">
                                    <?php if ($batch['status'] === 'PENDING_APPROVAL' && strtoupper((string)($_SESSION['role'] ?? '')) === 'ADMIN'): ?>
                                        <form method="POST" class="d-inline"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>"><input type="hidden" name="batch_id" value="<?php echo (int)$batch['id']; ?>"><button class="btn btn-sm btn-success" name="batch_action" value="approve">Approve</button></form>
                                        <form method="POST" class="d-inline"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>"><input type="hidden" name="batch_id" value="<?php echo (int)$batch['id']; ?>"><button class="btn btn-sm btn-outline-danger" name="batch_action" value="reject">Reject</button></form>
                                    <?php elseif (in_array($batch['status'], ['APPLIED', 'APPROVED'], true)): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Roll back this bulk batch where records have not changed since it was applied?');"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>"><input type="hidden" name="batch_id" value="<?php echo (int)$batch['id']; ?>"><button class="btn btn-sm btn-outline-warning" name="batch_action" value="rollback">Rollback</button></form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- HISTORY LOG -->
    <?php if (!empty($historyLogs)): ?>
        <div class="card shadow-sm mt-4">
            <div class="card-header bg-secondary text-white">
                <h6 class="mb-0"><i class="bi bi-clock-history"></i> Recent Bulk Updates</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>User</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historyLogs as $log): ?>
                            <tr>
                                <td><?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($log['username']); ?></td>
                                <td><?php echo htmlspecialchars($log['details']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    <?php if ($msg): ?>
        Swal.fire('Success', <?php echo json_encode($msg); ?>, 'success');
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.pathname);
        }
    <?php endif; ?>
    <?php if ($error): ?>
        Swal.fire('Error', <?php echo json_encode($error); ?>, 'error');
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.pathname);
        }
    <?php endif; ?>

    const deptMap = <?php echo json_encode($deptMap); ?>;

    function updateCounter() {
        const count = document.querySelectorAll('input[name="employee_ids[]"]:checked').length;
        const counterEl = document.getElementById('selectionCounter');
        if (counterEl) counterEl.textContent = `Selected: ${count}`;
    }

    function addDept(val) {
        if (!val) return;
        const input = document.getElementById('new_dept');
        let current = input.value;
        if (current) {
            if (!current.includes(val)) input.value = current + ', ' + val;
        } else {
            input.value = val;
        }
        document.getElementById('deptPicker').value = "";
        updateNewSections();
    }

    function updateNewSections() {
        const depts = document.getElementById('new_dept').value.split(',').map(s => s.trim()).filter(s => s !== '');
        const sect = document.getElementById('sectionPicker');
        sect.innerHTML = '<option value="">+ Add Section...</option>';

        depts.forEach(dept => {
            if (dept && deptMap[dept]) {
                const group = document.createElement('optgroup');
                group.label = dept;

                deptMap[dept].forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s;
                    opt.text = s;
                    group.appendChild(opt);
                });
                sect.appendChild(group);
            }
        });
    }

    function addSection(val) {
        const picker = document.getElementById('sectionPicker');
        if (val) appendSectionValue(val);
        picker.value = "";
    }

    function appendSectionValue(text) {
        const input = document.getElementById('new_section');
        let current = input.value;
        if (current) {
            if (!current.includes(text)) input.value = current + ', ' + text;
        } else {
            input.value = text;
        }
    }

    // Dynamic Group Helper
    function addGroup(val) {
        if (!val) return;
        const input = document.getElementById('new_group');
        let current = input.value;
        if (current) {
            if (!current.includes(val)) input.value = current + ', ' + val;
        } else {
            input.value = val;
        }
        document.getElementById('groupPicker').value = "";
    }

    document.getElementById('applyBtn').addEventListener('click', function(e) {
        e.preventDefault();

        const form = document.getElementById('bulkUpdateForm');
        const checkboxes = document.querySelectorAll('input[name="employee_ids[]"]:checked');

        if (checkboxes.length === 0) {
            Swal.fire('No Selection', 'Please select at least one employee.', 'warning');
            return;
        }

        const role = document.querySelector('select[name="new_system_role"]').value;
        const job = document.querySelector('input[name="new_job_title"]').value.trim();
        const dept = document.getElementById('new_dept').value;
        const section = document.getElementById('new_section').value;
        const group = document.getElementById('new_group').value;
        const gender = document.querySelector('select[name="new_gender"]').value;
        const agency = document.querySelector('select[name="new_agency"]').value;
        const status = document.querySelector('select[name="new_status"]').value;

        const clearFields = document.querySelectorAll('.clear-field:checked');
        if (!role && !job && !dept && !section && !group && !gender && !agency && !status && clearFields.length === 0) {
            Swal.fire('No Changes', 'Please select at least one field to update.', 'warning');
            return;
        }

        let summary = `<ul class="text-start">`;
        if (role) summary += `<li><strong>Role:</strong> ${document.createElement('div').appendChild(document.createTextNode(role)).parentNode.textContent}</li>`;
        if (job) summary += `<li><strong>Job Title:</strong> ${document.createElement('div').appendChild(document.createTextNode(job)).parentNode.textContent}</li>`;
        if (status) {
            summary += `<li class="text-danger"><strong>Status:</strong> ${document.createElement('div').appendChild(document.createTextNode(status)).parentNode.textContent}</li>`;
        }
        if (dept) summary += `<li><strong>Department:</strong> ${document.createElement('div').appendChild(document.createTextNode(dept)).parentNode.textContent}</li>`;
        if (section) summary += `<li><strong>Section:</strong> ${document.createElement('div').appendChild(document.createTextNode(section)).parentNode.textContent}</li>`;
        if (group) summary += `<li><strong>Group:</strong> ${document.createElement('div').appendChild(document.createTextNode(group)).parentNode.textContent}</li>`;
        if (gender) summary += `<li><strong>Gender:</strong> ${document.createElement('div').appendChild(document.createTextNode(gender)).parentNode.textContent}</li>`;
        if (agency) summary += `<li><strong>Agency:</strong> ${document.createElement('div').appendChild(document.createTextNode(agency)).parentNode.textContent}</li>`;
        summary += `</ul>`;

        Swal.fire({
            title: `Update ${checkboxes.length} Employees?`,
            html: `<p>The following changes will be applied:</p>${summary}${clearFields.length ? '<p class="text-warning">Selected fields will be cleared.</p>' : ''}<p class="text-danger small mt-2">The change will be recorded and can be rolled back.</p>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, Apply Updates'
        }).then((result) => {
            if (result.isConfirmed) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'update_roles';
                hiddenInput.value = '1';
                form.appendChild(hiddenInput);
                form.submit();
            }
        });
    });

    document.getElementById('selectFilteredBtn').addEventListener('click', function() {
        document.getElementById('selectAllFiltered').value = '1';
        document.querySelectorAll('.emp-check').forEach((checkbox) => {
            checkbox.checked = true;
        });
        updateCounter();
        this.classList.remove('btn-outline-primary');
        this.classList.add('btn-primary');
        this.innerHTML = '<i class="bi bi-check2-all"></i> All Filtered Selected';
    });

    // Save Scroll Position Before Reload
    window.addEventListener('beforeunload', () => {
        sessionStorage.setItem('bulk_update_scroll_pos', window.scrollY);
    });

    // Restore Scroll Position After Reload
    document.addEventListener("DOMContentLoaded", () => {
        const savedPos = sessionStorage.getItem('bulk_update_scroll_pos');
        if (savedPos !== null) {
            window.scrollTo({
                top: parseInt(savedPos),
                behavior: 'instant'
            });
            sessionStorage.removeItem('bulk_update_scroll_pos');
        }
    });
</script>

<?php require 'footer.php'; ?>