<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/FileService.php';

$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;

function normalizeEmployeePayload(array $data): array {
    if (empty($data)) {
        return [];
    }

    $aliases = [
        'first_name' => ['first_name', 'fname'],
        'middle_name' => ['middle_name', 'mname'],
        'last_name' => ['last_name', 'lname'],
        'email' => ['email', 'work_email'],
        'phone' => ['phone', 'contact_number', 'mobile'],
        'department' => ['department', 'dept'],
        'job_title' => ['job_title', 'position', 'designation'],
        'date_hired' => ['date_hired', 'hire_date'],
        'salary' => ['salary', 'monthly_salary'],
        'status' => ['status', 'employment_status'],
        'avatar_path' => ['avatar_path', 'profile_photo'],
        'system_role' => ['system_role', 'role'],
        'agency_name' => ['agency_name', 'agency'],
        'employment_type' => ['employment_type', 'employee_type'],
        'present_address' => ['present_address', 'address'],
        'permanent_address' => ['permanent_address', 'permanent_address_1'],
        'emergency_name' => ['emergency_name', 'emergency_contact_name'],
        'emergency_contact' => ['emergency_contact', 'emergency_phone'],
        'emergency_address' => ['emergency_address', 'emergency_address_1'],
        'sss_no' => ['sss_no', 'sss'],
        'tin_no' => ['tin_no', 'tin'],
        'pagibig_no' => ['pagibig_no', 'pagibig'],
        'philhealth_no' => ['philhealth_no', 'philhealth'],
        'gender' => ['gender'],
        'birth_date' => ['birth_date', 'date_of_birth'],
        'section' => ['section'],
    ];

    $clean = [];
    foreach ($aliases as $canonical => $options) {
        foreach ($options as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== '' && $data[$key] !== null) {
                $clean[$canonical] = $data[$key];
                break;
            }
        }
    }

    foreach (['emp_id', 'id', 'user_id'] as $key) {
        if (array_key_exists($key, $data) && !empty($data[$key])) {
            $clean['emp_id'] = $data[$key];
        }
    }

    $allowedColumns = ['emp_id', 'first_name', 'middle_name', 'last_name', 'email', 'phone', 'department', 'job_title', 'manager_id', 'date_hired', 'salary', 'status', 'avatar_path', 'system_role', 'agency_name', 'employment_type', 'gender', 'birth_date', 'section', 'present_address', 'permanent_address', 'emergency_name', 'emergency_contact', 'emergency_address', 'sss_no', 'tin_no', 'pagibig_no', 'philhealth_no'];
    $filtered = array_intersect_key($clean, array_flip($allowedColumns));
    return $filtered;
}

function cleanupRejectedUploadPayload(array $payload, string $vaultPath): void {
    $filePath = $payload['file_path'] ?? '';
    if ($filePath === '') {
        return;
    }

    $candidates = [
        rtrim($vaultPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($filePath, DIRECTORY_SEPARATOR),
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . ltrim($filePath, DIRECTORY_SEPARATOR),
        __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . ltrim($filePath, DIRECTORY_SEPARATOR),
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && file_exists($candidate)) {
            @unlink($candidate);
            return;
        }
    }
}

session_start();

// Generate CSRF token if not present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// SECURITY: Verify authentication first, then check authorization
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: index.php");
    exit;
}

if (!in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    header("Location: index.php");
    exit;
}

// === HANDLE APPROVAL / REJECTION ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // CSRF Validation
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('CSRF token validation failed');
    }

    // Validate and sanitize POST values
    $req_id  = (int)$_POST['req_id'];
    $action  = $_POST['action'];
    $tab     = $_POST['tab_name'] ?? '';
    $adminId = $_SESSION['user_id'];

    // Validate action against allowed values
    $allowedActions = ['approve', 'reject'];
    if (!in_array($action, $allowedActions)) {
        http_response_code(400);
        die('Invalid action');
    }

    // Validate tab against allowed values
    $allowedTabs = ['hires', 'edits', 'docs', 'doc-edits', 'tickets'];
    if (!in_array($tab, $allowedTabs)) {
        $tab = 'hires'; // Safe default
    }

    // Capture rejection reason if sent (trim but don't escape yet - escape at render time)
    $reject_reason = trim($_POST['reject_reason'] ?? '');

    // FETCH DETAILS
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ?");
    $stmt->execute([$req_id]);
    $req = $stmt->fetch();

    if ($req) {
        $data = json_decode($req['json_payload'] ?? '', true);
        if (!is_array($data)) {
            $data = [];
        }
        $logger = new Logger($pdo);

        if ($action === 'approve') {
            $pdo->beginTransaction();
            try {
                // 1. ADD EMPLOYEE
                if ($req['request_type'] === 'ADD_EMPLOYEE') {
                    $dupCheck = $pdo->prepare("SELECT status FROM employees WHERE emp_id = ?");
                    $dupCheck->execute([$data['emp_id'] ?? '']);
                    if ($dupCheck->rowCount() > 0) {
                        throw new Exception("The employee ID '" . ($data['emp_id'] ?? '') . "' is already in use.");
                    }

                    unset($data['request_note']);
                    $filteredData = normalizeEmployeePayload($data);
                    if (empty($filteredData)) {
                        throw new Exception('No valid data provided for employee insertion');
                    }

                    $cols = implode(", ", array_keys($filteredData));
                    $vals = implode(", ", array_fill(0, count($filteredData), "?"));
                    $pdo->prepare("INSERT INTO employees ($cols) VALUES ($vals)")->execute(array_values($filteredData));

                    if (!empty($data['email'])) {
                        $subject = "Welcome to TES Philippines!";
                        $body    = "<h3>Hi " . htmlspecialchars($data['first_name'] ?? 'Employee') . ",</h3>";
                        $body   .= "<p>Welcome to the team! We are excited to have you on board as our new <strong>" . htmlspecialchars($data['job_title'] ?? 'Team Member') . "</strong>.</p>";
                        $body   .= "<p><strong>Employee ID:</strong> " . htmlspecialchars($data['emp_id'] ?? '') . "</p>";
                        $body   .= "<p>Please coordinate with your department head for your initial schedule.</p>";
                        $body   .= "<br><p>Best Regards,<br>Human Resources</p>";

                        $headers  = "MIME-Version: 1.0" . "\r\n";
                        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                        $headers .= "From: HR System <no-reply@hrsystem.com>" . "\r\n";

                        @mail($data['email'], $subject, $body, $headers);
                    }

                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Request Approved', ?, 'success')")
                        ->execute([$req['user_id'], "Your request to add employee " . ($data['first_name'] ?? 'the employee') . " was approved."]);

                    $logger->log($adminId, 'APPROVED_HIRE', "Approved New Employee: " . ($data['first_name'] ?? '') . " " . ($data['last_name'] ?? ''));
                }
                elseif ($req['request_type'] === 'EDIT_PROFILE') {
                    $targetId = (int) $req['target_id'];
                    $oldIdStmt = $pdo->prepare("SELECT emp_id FROM employees WHERE id = ?");
                    $oldIdStmt->execute([$targetId]);
                    $oldEmp = $oldIdStmt->fetch();

                    unset($data['request_note']);
                    $filteredData = normalizeEmployeePayload($data);

                    if (!empty($filteredData)) {
                        $setParts = [];
                        $updateValues = [];
                        foreach ($filteredData as $key => $val) {
                            $setParts[] = "$key = ?";
                            $updateValues[] = $val;
                        }
                        $updateValues[] = $targetId;
                        $sql = "UPDATE employees SET " . implode(', ', $setParts) . " WHERE id = ?";
                        $pdo->prepare($sql)->execute($updateValues);
                    }

                    if ($oldEmp && !empty($data['emp_id']) && $oldEmp['emp_id'] !== $data['emp_id']) {
                        $pdo->prepare("UPDATE documents SET employee_id = ? WHERE employee_id = ?")->execute([$data['emp_id'], $oldEmp['emp_id']]);
                    }

                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Update Approved', 'Your profile update request was approved.', 'success')")
                        ->execute([$req['user_id']]);

                    $logger->log($adminId, 'APPROVED_EDIT', "Approved Profile Edit for ID: " . $req['target_id']);
                }
                elseif ($req['request_type'] === 'UPLOAD_DOC') {
                    $sql = "INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, expiry_date, description, uploaded_by) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)";
                    $pdo->prepare($sql)->execute([
                        $data['employee_id'] ?? 0,
                        $data['original_name'] ?? '',
                        $data['file_path'] ?? '',
                        $data['category'] ?? '',
                        $data['expiry_date'] ?? null,
                        $data['description'] ?? '',
                        $req['user_id']
                    ]);

                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Document Approved', ?, 'success')")
                        ->execute([$req['user_id'], "Document '" . ($data['original_name'] ?? 'Document') . "' has been approved."]);

                    $logger->log($adminId, 'APPROVED_DOC', "Approved Document: " . ($data['original_name'] ?? 'Document'));
                }
                elseif ($req['request_type'] === 'RESOLVE_ALERT') {
                    $docId = $data['doc_id'] ?? 0;
                    $note  = $data['note'] ?? '';
                    $pdo->prepare("UPDATE documents SET is_resolved = 1, resolution_note = ? WHERE id = ?")->execute([$note, $docId]);

                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Resolution Approved', 'Your resolution report was approved.', 'success')")
                        ->execute([$req['user_id']]);

                    $logger->log($adminId, 'APPROVED_RESOLUTION', "Approved resolution for Doc ID $docId");
                }
                elseif ($req['request_type'] === 'EDIT_DOC') {
                    $docId = (int) $req['target_id'];
                    $updateCols = [];
                    $updateParams = [];

                    if (isset($data['new_name'])) {
                        $updateCols[] = "original_name = ?";
                        $updateParams[] = $data['new_name'];
                    }
                    if (isset($data['new_category'])) {
                        $updateCols[] = "category = ?";
                        $updateParams[] = $data['new_category'];
                    }
                    if (isset($data['move_to_emp_id'])) {
                        $updateCols[] = "employee_id = ?";
                        $updateParams[] = $data['move_to_emp_id'];
                    }

                    if (!empty($updateCols)) {
                        $updateCols[] = "updated_at = NOW()";
                        $updateCols[] = "updated_by = ?";
                        $updateParams[] = $req['user_id'];
                        $updateParams[] = $docId;
                        $sql = "UPDATE documents SET " . implode(', ', $updateCols) . " WHERE id = ?";
                        $pdo->prepare($sql)->execute($updateParams);
                    }

                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Document Edit Approved', ?, 'success')")
                        ->execute([$req['user_id'], "Your edit for document '" . ($data['original_details']['original_name'] ?? $docId) . "' was approved."]);

                    $logger->log($adminId, 'APPROVED_EDIT_DOC', "Approved edit for Doc ID: " . $docId);
                }

                $adminComment = $_SESSION['username'] ?? 'admin';
                $pdo->prepare("UPDATE requests SET status = 'APPROVED', admin_comment = ? WHERE id = ?")->execute(["Approved by " . $adminComment, $req_id]);
                $msg = "Request Approved Successfully";
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                $pdo->prepare("UPDATE requests SET status = 'REJECTED', admin_comment = ? WHERE id = ?")->execute(["Auto-rejected: " . $e->getMessage(), $req_id]);
                $msg = "Error: " . $e->getMessage();
            }
        } elseif ($action === 'reject') {
            $msgTitle = "Request Rejected";
            $msgBody  = "Your request (" . $req['request_type'] . ") was rejected.";

            if (!empty($reject_reason)) {
                $msgBody .= "\n\nReason: " . $reject_reason;
            }

            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'danger')")
                ->execute([$req['user_id'], $msgTitle, $msgBody]);

            $pdo->prepare("UPDATE requests SET status = 'REJECTED', admin_comment = ? WHERE id = ?")->execute([$reject_reason ?: 'Rejected by admin', $req_id]);

            if (($req['request_type'] ?? '') === 'UPLOAD_DOC') {
                cleanupRejectedUploadPayload($data, $vaultPath);
            }

            $logger->log($adminId, 'REJECTED_REQUEST', "Rejected request: " . $req['request_type']);
            $msg = "Request Rejected & User Notified";
        }
    }

    header("Location: admin_approval.php?msg=" . rawurlencode($msg) . "&tab=" . rawurlencode($tab));
    exit;
}

/// FETCH REQUESTS
$newHires = $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE r.status = 'PENDING' AND request_type='ADD_EMPLOYEE'")->fetchAll();
$edits    = $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE r.status = 'PENDING' AND request_type='EDIT_PROFILE'")->fetchAll();
$docs     = $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE r.status = 'PENDING' AND request_type='UPLOAD_DOC'")->fetchAll();
$doc_edits = $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE r.status = 'PENDING' AND request_type='EDIT_DOC'")->fetchAll();
$tickets  = $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE r.status = 'PENDING' AND request_type='RESOLVE_ALERT'")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Approvals</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">⬅ Dashboard</a>
            <span class="navbar-text text-white">Approval Center</span>
        </div>
    </nav>

    <div class="container">
        <?php if (isset($_GET['msg'])): ?>
            <div class='alert alert-warning'><?php echo htmlspecialchars($_GET['msg']); ?></div>
            <script>
                // Clear message on load
                if (window.history.replaceState) {
                    const url = new URL(window.location);
                    url.searchParams.delete('msg');
                    window.history.replaceState(null, '', url);
                }
            </script>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <ul class="nav nav-tabs card-header-tabs" id="approvalTabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" id="tab-btn-hires" data-bs-toggle="tab" data-bs-target="#tab-hires">New Hires (<?php echo count($newHires); ?>)</button></li>
                    <li class="nav-item"><button class="nav-link" id="tab-btn-edits" data-bs-toggle="tab" data-bs-target="#tab-edits">Edits (<?php echo count($edits); ?>)</button></li>
                    <li class="nav-item"><button class="nav-link" id="tab-btn-docs" data-bs-toggle="tab" data-bs-target="#tab-docs">Documents (<?php echo count($docs); ?>)</button></li>
                    <li class="nav-item"><button class="nav-link" id="tab-btn-doc-edits" data-bs-toggle="tab" data-bs-target="#tab-doc-edits">Doc Edits (<?php echo count($doc_edits); ?>)</button></li>
                    <li class="nav-item"><button class="nav-link text-primary fw-bold" id="tab-btn-tickets" data-bs-toggle="tab" data-bs-target="#tab-tickets">Resolutions (<?php echo count($tickets); ?>)</button></li>
                </ul>
            </div>

            <div class="card-body p-0 table-responsive">
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tab-hires"><?php renderTable($newHires, 'hire'); ?></div>
                    <div class="tab-pane fade" id="tab-edits"><?php renderTable($edits, 'edit'); ?></div>
                    <div class="tab-pane fade" id="tab-docs"><?php renderTable($docs, 'doc'); ?></div>
                    <div class="tab-pane fade" id="tab-doc-edits"><?php renderTable($doc_edits, 'doc_edit'); ?></div>
                    <div class="tab-pane fade" id="tab-tickets"><?php renderTable($tickets, 'ticket'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Request Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalContent"></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Reject Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="req_id" id="reject_req_id">
                        <input type="hidden" name="tab_name" id="reject_tab_name">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                        <label class="form-label fw-bold">Reason for Rejection:</label>
                        <textarea name="reject_reason" class="form-control" rows="3" placeholder="e.g. Photo is blurry, please retake." required maxlength="255"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <?php
    // HELPER FUNCTION TO RENDER TABLES
    function renderTable($requests, $type)
    {
        global $pdo; // Access DB for lookups
        if (count($requests) == 0) {
            echo "<div class='p-4 text-center text-muted'>No pending requests.</div>";
            return;
        }

        // Map tab names
        $tabName = match ($type) {
            'hire' => 'hires',
            'edit' => 'edits',
            'doc' => 'docs',
            'doc_edit' => 'doc-edits',
            'ticket' => 'tickets'
        };

        echo '<table class="table table-hover mb-0"><thead class="table-light"><tr><th>Date</th><th>User</th><th>Summary</th><th class="text-end">Actions</th></tr></thead><tbody>';

        foreach ($requests as $r) {
            $data = json_decode($r['json_payload'], true);

            // [FIX] Ensure doc_name exists for old records (Ticket Resolutions)
            if ($type == 'ticket' && empty($data['doc_name']) && isset($data['doc_id'])) {
                $stmt = $pdo->prepare("SELECT original_name FROM documents WHERE id = ?");
                $stmt->execute([$data['doc_id']]);
                $data['doc_name'] = $stmt->fetchColumn() ?: 'Unknown File';
            }

            $jsonData = htmlspecialchars(json_encode($data), ENT_QUOTES, 'UTF-8');

            // Dynamic Summary
            if ($type == 'hire') $summary = "<strong>New Employee:</strong> " . htmlspecialchars($data['first_name'], ENT_QUOTES, 'UTF-8') . " " . htmlspecialchars($data['last_name'], ENT_QUOTES, 'UTF-8');
            elseif ($type == 'edit') $summary = "<strong>Update Profile:</strong> ID " . intval($r['target_id']);
            elseif ($type == 'doc') $summary = "<strong>File Upload:</strong> " . htmlspecialchars($data['original_name'], ENT_QUOTES, 'UTF-8');
            elseif ($type == 'doc_edit') {
                $orig = $data['original_details'] ?? [];
                $summary = "<strong>Edit Document:</strong> " . htmlspecialchars($orig['original_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
            } elseif ($type == 'ticket') {
                $docLabel = isset($data['doc_name']) ? " (" . htmlspecialchars($data['doc_name'], ENT_QUOTES, 'UTF-8') . ")" : " (Doc #" . intval($data['doc_id'] ?? 0) . ")";
                $summary = "<strong class='text-primary'><i class='bi bi-check2-circle'></i> Action Taken{$docLabel}:</strong> " . htmlspecialchars(substr($data['note'], 0, 100), ENT_QUOTES, 'UTF-8') . "...";
            }

            echo "<tr>
            <td>" . date('M d, H:i', strtotime($r['created_at'])) . "</td>
            <td><span class='badge bg-secondary'>{$r['username']}</span></td>
            <td>$summary</td>
            <td class='text-end'>
                <button class='btn btn-sm btn-info text-white me-2' onclick='openPreview($jsonData, \"$type\", {$r['id']})' title='View Details'><i class='bi bi-eye'></i> View</button>
                
                <form method='POST' class='d-inline'>
                    <input type='hidden' name='req_id' value='{$r['id']}'>
                    <input type='hidden' name='tab_name' value='$tabName'>
                    <input type='hidden' name='csrf_token' value='" . htmlspecialchars($_SESSION['csrf_token']) . "'>
                    <button name='action' value='approve' class='btn btn-sm btn-success' title='Approve'><i class='bi bi-check-lg'></i></button>
                </form>
                
                <button type='button' class='btn btn-sm btn-danger' onclick='openRejectModal({$r['id']}, \"$tabName\")' title='Reject with Note'><i class='bi bi-x-lg'></i></button>
            </td>
        </tr>";
        }
        echo '</tbody></table>';
    }
    ?>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const urlParams = new URLSearchParams(window.location.search);
            const activeTab = urlParams.get('tab');
            if (activeTab) {
                let btnId = '';
                if (activeTab === 'hires') btnId = 'tab-btn-hires';
                if (activeTab === 'edits') btnId = 'tab-btn-edits';
                if (activeTab === 'docs') btnId = 'tab-btn-docs';
                if (activeTab === 'doc-edits') btnId = 'tab-btn-doc-edits';
                if (activeTab === 'tickets') btnId = 'tab-btn-tickets';

                const triggerEl = document.getElementById(btnId);
                if (triggerEl) {
                    new bootstrap.Tab(triggerEl).show();
                }
            }
        });

        // THIS WAS MISSING BEFORE - IT OPENS THE REJECT MODAL
        function openRejectModal(reqId, tabName) {
            document.getElementById('reject_req_id').value = reqId;
            document.getElementById('reject_tab_name').value = tabName;
            new bootstrap.Modal(document.getElementById('rejectModal')).show();
        }

        function openPreview(data, type, reqId = null) {
            let content = '';
            const modalBody = document.getElementById('modalContent');

            function escapeHtml(text) {
                if (text == null) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            // 1. TICKET (RESOLUTION)
            if (type === 'ticket') {
                content += `<div class="alert alert-warning border-start border-5 border-warning shadow-sm">
                        <h5 class="text-dark"><i class="bi bi-clipboard-check"></i> Resolution Report</h5>
                        <hr>
                        <p class="mb-1 text-primary fw-bold small text-uppercase">Action Taken${data.doc_name ? ' for ' + escapeHtml(data.doc_name) : ''}:</p>
                        <p class="fs-5 fw-bold text-dark">"${escapeHtml(data.note)}"</p>
                    </div>`;
            }
            // 2. DOCUMENT
            else if (type === 'doc') {
                let filePath = 'view_pending.php?id=' + reqId; // [FIX] Use Viewer Script
                let fileExt = data.original_name.split('.').pop().toLowerCase();

                content += `<h5>File: ${escapeHtml(data.original_name)}</h5>
                    <p>Category: <span class="badge bg-primary">${escapeHtml(data.category)}</span></p>
                    <div class="alert alert-info p-2 mb-3"><strong>Notes:</strong><br>${escapeHtml(data.description || 'None')}</div>`;

                if (fileExt === 'pdf') {
                    content += `<object data="${filePath}" type="application/pdf" width="100%" height="500px"><p>Unable to display PDF. <a href="${filePath}" target="_blank">Download File</a></p></object>`;
                } else {
                    content += `<img src="${filePath}" style="max-width:100%; max-height:400px; display:block; margin:0 auto;" onerror="this.src='../assets/error_image.png';">`;
                }
            }
            // 4. DOCUMENT EDIT
            else if (type === 'doc_edit') {
                const orig = data.original_details || {};
                let changesHtml = '<ul class="list-group">';
                let hasChanges = false;

                if (data.new_name && data.new_name !== orig.original_name) {
                    hasChanges = true;
                    changesHtml += `<li class="list-group-item"><strong>Name:</strong><br><del class="text-danger">${escapeHtml(orig.original_name || '')}</del><br><ins class="text-success">${escapeHtml(data.new_name)}</ins></li>`;
                }
                if (data.new_category && data.new_category !== orig.category) {
                    hasChanges = true;
                    changesHtml += `<li class="list-group-item"><strong>Category:</strong><br><del class="text-danger">${escapeHtml(orig.category || '')}</del><br><ins class="text-success">${escapeHtml(data.new_category)}</ins></li>`;
                }
                if (data.move_to_emp_id && data.move_to_emp_id !== orig.employee_id) {
                    hasChanges = true;
                    changesHtml += `<li class="list-group-item"><strong>Move To Employee:</strong><br><del class="text-danger">${escapeHtml(orig.employee_id || '')}</del><br><ins class="text-success">${escapeHtml(data.move_to_emp_id)}</ins></li>`;
                }
                changesHtml += '</ul>';

                if (!hasChanges) changesHtml = '<p class="text-muted">No changes were requested.</p>';

                content += `<h5>Document Edit Request</h5>
                            <p class="mb-2">Original File: <strong>${escapeHtml(orig.original_name || 'N/A')}</strong></p>${changesHtml}`;
            }
            // 3. PROFILE ADD / EDIT
            else {
                // --- DEBUG MODE: ALWAYS SHOW NOTE STATUS ---
                if (data.request_note && data.request_note.trim() !== "") {
                    // Note Exists
                    content += `<div class="alert alert-warning border-start border-5 border-warning shadow-sm mb-3">
                            <h6 class="text-dark fw-bold"><i class="bi bi-chat-left-text-fill me-2"></i> Note from Staff:</h6>
                            <p class="mb-0 text-dark fs-6">"${escapeHtml(data.request_note)}"</p>
                        </div>`;
                } else {
                    // Note Missing 
                    content += `<div class="alert alert-secondary border-start border-5 border-secondary shadow-sm mb-3">
                            <h6 class="text-muted fw-bold"><i class="bi bi-chat-left-text me-2"></i> Note from Staff:</h6>
                            <p class="mb-0 text-muted small"><em>(No note was entered for this request)</em></p>
                        </div>`;
                }

                content += '<table class="table table-bordered table-sm">';
                for (const [key, value] of Object.entries(data)) {
                    if (value && key !== 'avatar_path' && key !== 'request_note') {
                        let label = key.replace(/_/g, ' ').toUpperCase();
                        content += `<tr><th class="bg-light w-25">${escapeHtml(label)}</th><td>${escapeHtml(value.toString())}</td></tr>`;
                    }
                }
                content += '</table>';
            }

            modalBody.innerHTML = content;
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        }
    </script>
</body>

</html>