<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/Validator.php';
require_once '../src/helpers.php';
require_once __DIR__ . '/options.php';
session_start();
checkSessionTimeout($pdo);

$role = strtoupper((string)($_SESSION['role'] ?? ''));
if (!isset($_SESSION['user_id']) || !in_array($role, ['ADMIN', 'MANAGER', 'HR'], true)) {
    header('Location: index.php');
    exit;
}

$security = new Security($pdo);
$logger = new Logger($pdo);
$csrf = $security->generateCSRF();
$allowedFields = ['job_title', 'dept', 'section', 'group_name', 'system_role', 'agency_name', 'employment_type', 'gender', 'status'];
$preview = $_SESSION['bulk_csv_preview'] ?? null;
$error = '';
$msg = '';

function csvValue(array $row, string $field): ?string
{
    if (!array_key_exists($field, $row) || trim((string)$row[$field]) === '') return null;
    $value = trim((string)$row[$field]);
    return $value === '__CLEAR__' ? null : $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $security->checkCSRF($_POST['csrf_token'] ?? '');
        if (isset($_POST['preview_csv'])) {
            if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Please select a valid CSV file.');
            if ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) throw new RuntimeException('CSV files must be 5 MB or smaller.');
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'rb');
            $header = fgetcsv($handle);
            if (!$header || !in_array('id', $header, true)) throw new RuntimeException('CSV must include an id column.');
            $header = array_map(static fn($value) => strtolower(trim((string)$value)), $header);
            $unknown = array_diff($header, array_merge(['id', 'emp_id', 'first_name', 'last_name'], $allowedFields));
            if ($unknown) throw new RuntimeException('Unsupported CSV column: ' . reset($unknown));
            $rows = [];
            while (($line = fgetcsv($handle)) !== false) {
                if (count(array_filter($line, static fn($value) => trim((string)$value) !== '')) === 0) continue;
                $row = array_combine($header, array_pad($line, count($header), ''));
                $id = (int)($row['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('Every CSV row must have a numeric employee id.');
                $values = [];
                foreach ($allowedFields as $field) {
                    if (!array_key_exists($field, $row) || trim((string)$row[$field]) === '') continue;
                    if ($field === 'status' && trim((string)$row[$field]) === '__CLEAR__') {
                        throw new RuntimeException('Employee status cannot be cleared. Use a configured status value.');
                    }
                    $values[$field] = csvValue($row, $field);
                    if ($field === 'status' && !in_array($values[$field], $employee_statuses, true)) {
                        throw new RuntimeException('CSV contains an employee status that is not configured: ' . (string)$values[$field]);
                    }
                }
                if (!$values) continue;
                $stmt = $pdo->prepare('SELECT id, first_name, last_name, ' . implode(', ', $allowedFields) . ' FROM employees WHERE id = ? AND deleted_at IS NULL');
                $stmt->execute([$id]);
                $employee = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$employee) throw new RuntimeException("Employee ID $id was not found or is archived.");
                $changes = [];
                foreach ($values as $field => $newValue) {
                    if ((string)($employee[$field] ?? '') !== (string)$newValue) {
                        $changes[] = ['field_name' => $field, 'old_value' => $employee[$field] ?? null, 'new_value' => $newValue];
                    }
                }
                if ($changes) $rows[] = ['employee_id' => $id, 'name' => $employee['last_name'] . ', ' . $employee['first_name'], 'changes' => $changes];
                if (count($rows) > 5000) throw new RuntimeException('CSV imports are limited to 5,000 changed employees per batch.');
            }
            fclose($handle);
            $_SESSION['bulk_csv_preview'] = $rows;
            $preview = $rows;
            $msg = 'CSV validated. Review the proposed changes before applying them.';
        } elseif (isset($_POST['apply_csv'])) {
            $rows = $_SESSION['bulk_csv_preview'] ?? [];
            if (!$rows) throw new RuntimeException('There is no validated CSV batch to apply.');
            $sensitive = false;
            foreach ($rows as $row) foreach ($row['changes'] as $change) if (in_array($change['field_name'], ['system_role', 'status'], true)) $sensitive = true;
            $batchStatus = ($sensitive && $role !== 'ADMIN') ? 'PENDING_APPROVAL' : 'APPLIED';
            $pdo->beginTransaction();
            $batchUuid = sprintf('%s-%s-%s', uniqid('', true), bin2hex(random_bytes(4)), date('YmdHis'));
            $batchStmt = $pdo->prepare('INSERT INTO bulk_update_batches (batch_uuid, requested_by, reason, source, status) VALUES (?, ?, ?, "CSV", ?)');
            $batchStmt->execute([$batchUuid, $_SESSION['user_id'], trim($_POST['reason'] ?? '') ?: null, $batchStatus]);
            $batchId = (int)$pdo->lastInsertId();
            $changeStmt = $pdo->prepare('INSERT INTO bulk_update_changes (batch_id, employee_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)');
            foreach ($rows as $row) foreach ($row['changes'] as $change) {
                $changeStmt->execute([$batchId, $row['employee_id'], $change['field_name'], $change['old_value'], $change['new_value']]);
                if ($batchStatus === 'APPLIED') {
                    $stmt = $pdo->prepare("UPDATE employees SET `{$change['field_name']}` = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL");
                    $stmt->execute([$change['new_value'], $row['employee_id']]);
                }
            }
            $pdo->commit();
            unset($_SESSION['bulk_csv_preview']);
            $logger->log($_SESSION['user_id'], $batchStatus === 'APPLIED' ? 'BULK_CSV_UPDATE' : 'BULK_CSV_PENDING', "CSV batch $batchId for " . count($rows) . ' employees.');
            $msg = $batchStatus === 'APPLIED' ? "CSV batch #$batchId applied." : "CSV batch #$batchId submitted for admin approval.";
            $preview = null;
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception->getMessage();
    }
}
require 'header.php';
?>
<div class="container pb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Bulk CSV Update</h2>
        <a href="bulk_update_roles.php" class="btn btn-outline-secondary">Back to Bulk Update</a>
    </div>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
    <div class="alert alert-info">Use the CSV exported from Bulk Update. Leave a cell blank to keep its value. Use <code>__CLEAR__</code> to remove a value. Email notifications are disabled under MHI policy.</div>
    <form method="POST" enctype="multipart/form-data" class="card card-body mb-4">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <label class="form-label fw-bold" for="csv_file">CSV file</label>
        <input class="form-control mb-3" type="file" name="csv_file" id="csv_file" accept=".csv,text/csv" required>
        <button class="btn btn-primary" type="submit" name="preview_csv" value="1">Validate and Preview</button>
    </form>
    <?php if ($preview !== null): ?>
        <div class="card">
            <div class="card-header fw-bold">Preview: <?php echo count($preview); ?> employees changed</div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Field</th>
                            <th>Before</th>
                            <th>After</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview as $row): foreach ($row['changes'] as $change): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><?php echo htmlspecialchars($change['field_name']); ?></td>
                                    <td><?php echo htmlspecialchars((string)($change['old_value'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($change['new_value'] ?? '')); ?></td>
                                </tr>
                        <?php endforeach;
                        endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($preview): ?><div class="card-footer">
                    <form method="POST"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>"><input type="text" name="reason" class="form-control mb-2" maxlength="200" placeholder="Reason for this CSV update"><button class="btn btn-success" name="apply_csv" value="1">Apply Validated CSV</button></form>
                </div><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php require 'footer.php'; ?>