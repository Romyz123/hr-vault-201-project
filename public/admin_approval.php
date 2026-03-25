<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// Load Config for Vault Path
$config = require '../config/config.php';

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

// [NEW] Fetch widget visibility settings
$approvalWidgetsSetting = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'approval_widgets'")->fetchColumn();
$enabledWidgets = ['hires', 'edits', 'docs', 'doc-edits', 'tickets']; // Default fallback
if ($approvalWidgetsSetting !== false && $approvalWidgetsSetting !== '') {
    $decoded = json_decode($approvalWidgetsSetting, true);
    if (is_array($decoded)) { // [FIX] Removed !empty() so admins can explicitly hide ALL widgets
        $enabledWidgets = $decoded;
    }
}




// === HANDLE APPROVAL / REJECTION ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // CSRF Validation
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('CSRF token validation failed');
    }

    // Validate and sanitize POST values
    $req_id  = isset($_POST['req_id']) ? (int)$_POST['req_id'] : 0;
    $rawAction = $_POST['action'];
    $tab     = $_POST['tab_name'] ?? '';
    $adminId = $_SESSION['user_id'];

    // Validate action against allowed values
    $allowedActions = ['approve', 'reject', 'bulk_approve', 'bulk_reject'];
    if (!in_array($rawAction, $allowedActions)) {
        http_response_code(400);
        die('Invalid action');
    }

    $isBulk = strpos($rawAction, 'bulk_') === 0;
    $coreAction = str_replace('bulk_', '', $rawAction);

    // Validate tab against allowed values
    $allowedTabs = ['hires', 'edits', 'docs', 'doc-edits', 'tickets'];
    if (!in_array($tab, $allowedTabs)) {
        $tab = 'hires'; // Safe default
    }

    $reqIds = [];
    if ($isBulk) {
        $reqIds = isset($_POST['req_ids']) && is_array($_POST['req_ids']) ? $_POST['req_ids'] : [];
    } else {
        $reqIds = isset($_POST['req_id']) ? [(int)$_POST['req_id']] : [];
    }
    if (empty($reqIds)) {
        header("Location: admin_approval.php?msg=" . rawurlencode("⚠️ No requests selected.") . "&tab=" . rawurlencode($tab));
        exit;
    }

    // Capture rejection reason if sent (trim but don't escape yet - escape at render time)
    $reject_reason = trim($_POST['reject_reason'] ?? '');
    if (mb_strlen($reject_reason, 'UTF-8') > 255) {
        die('Rejection reason too long (Max 255 chars)');
    }

    $logger = new Logger($pdo);
    $successCount = 0;
    $failCount = 0;
    $failMsgs = [];

    foreach ($reqIds as $current_req_id) {
        $current_req_id = (int)$current_req_id;
        $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ? AND status = 'PENDING'");
        $stmt->execute([$current_req_id]);
        $req = $stmt->fetch();

        if (!$req) continue; // Skip if already processed or deleted

        $data = json_decode($req['json_payload'], true);

        if ($coreAction === 'approve') {
            try {
                $pdo->beginTransaction(); // [DATA INTEGRITY] Start Transaction

                // 1. ADD EMPLOYEE
                if ($req['request_type'] === 'ADD_EMPLOYEE') {
                    // Pre-check for duplicate ID
                    $dupCheck = $pdo->prepare("SELECT status FROM employees WHERE emp_id = ?");
                    $dupCheck->execute([$data['emp_id']]);
                    if ($dupCheck->rowCount() > 0) {
                        throw new Exception("The ID '" . $data['emp_id'] . "' is already in use.");
                    }

                    // SAFETY: Remove the note so it doesn't break the SQL INSERT
                    unset($data['request_note']);

                    // Whitelist allowed columns for employees table
                    $allowedColumns = ['emp_id', 'first_name', 'last_name', 'email', 'phone', 'department', 'job_title', 'manager_id', 'date_hired', 'salary', 'status', 'avatar_path'];
                    $filteredData = array_intersect_key($data, array_flip($allowedColumns));

                    if (empty($filteredData)) {
                        throw new Exception('No valid data provided for employee insertion');
                    }

                    $cols = implode(", ", array_keys($filteredData));
                    $vals = implode(", ", array_fill(0, count($filteredData), "?"));
                    $pdo->prepare("INSERT INTO employees ($cols) VALUES ($vals)")->execute(array_values($filteredData));

                    // [MHI POLICY] Automated Welcome Email disabled.

                    // NOTIFY SUCCESS
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Request Approved', ?, 'success')")
                        ->execute([$req['user_id'], "Your request to add employee " . $data['first_name'] . " was approved."]);

                    $logger->log($adminId, 'APPROVED_HIRE', "Approved New Employee: " . $data['first_name'] . " " . $data['last_name']);
                }
                // 2. EDIT PROFILE
                elseif ($req['request_type'] === 'EDIT_PROFILE') {
                    $targetId = $req['target_id'];
                    $newEmpId = $data['emp_id'] ?? null;

                    // CRITICAL: Fetch the current emp_id before updating
                    $oldIdStmt = $pdo->prepare("SELECT emp_id FROM employees WHERE id = ?");
                    $oldIdStmt->execute([$targetId]);
                    $oldEmp = $oldIdStmt->fetch();

                    // [DATA INTEGRITY] Check duplicate if ID is changing
                    if ($oldEmp && $newEmpId && $newEmpId !== $oldEmp['emp_id']) {
                        $chk = $pdo->prepare("SELECT id FROM employees WHERE emp_id = ? AND id != ?");
                        $chk->execute([$newEmpId, $targetId]);
                        if ($chk->fetch()) {
                            throw new Exception("New Employee ID '$newEmpId' is already taken by another user.");
                        }
                    }

                    // SAFETY: Remove the note so it doesn't break the SQL UPDATE
                    unset($data['request_note']);

                    // Whitelist allowed columns for employees table
                    $allowedColumns = ['emp_id', 'first_name', 'last_name', 'email', 'phone', 'department', 'job_title', 'manager_id', 'date_hired', 'salary', 'status', 'avatar_path'];
                    $filteredData = array_intersect_key($data, array_flip($allowedColumns));

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

                    // CRITICAL: Cascade Update if ID changed (Fixing Ghost Records)
                    if ($oldEmp && $newEmpId && $oldEmp['emp_id'] !== $newEmpId) {
                        $oldStr = $oldEmp['emp_id'];
                        $pdo->prepare("UPDATE documents SET employee_id = ? WHERE employee_id = ?")->execute([$newEmpId, $oldStr]);
                        $pdo->prepare("UPDATE disciplinary_cases SET employee_id = ? WHERE employee_id = ?")->execute([$newEmpId, $oldStr]);
                        $pdo->prepare("UPDATE maintenance_logs SET employee_id = ? WHERE employee_id = ?")->execute([$newEmpId, $oldStr]);
                        $pdo->prepare("UPDATE document_exemptions SET employee_id = ? WHERE employee_id = ?")->execute([$newEmpId, $oldStr]);
                    }

                    // NOTIFY SUCCESS
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Update Approved', 'Your profile update request was approved.', 'success')")
                        ->execute([$req['user_id']]);

                    $logger->log($adminId, 'APPROVED_EDIT', "Approved Profile Edit for ID: " . $req['target_id']);
                }
                // 3. UPLOAD DOCUMENT
                elseif ($req['request_type'] === 'UPLOAD_DOC') {
                    $sql = "INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, expiry_date, description, uploaded_by) 
                            VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)";
                    $pdo->prepare($sql)->execute([
                        $data['employee_id'],
                        $data['original_name'],
                        $data['file_path'],
                        $data['category'],
                        $data['expiry_date'],
                        $data['description'],
                        $req['user_id']
                    ]);

                    // NOTIFY SUCCESS
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Document Approved', ?, 'success')")
                        ->execute([$req['user_id'], "Document '" . $data['original_name'] . "' has been approved."]);

                    $logger->log($adminId, 'APPROVED_DOC', "Approved Document: " . $data['original_name']);
                }
                // 4. RESOLVE ALERT (TICKET)
                elseif ($req['request_type'] === 'RESOLVE_ALERT') {
                    $docId = $data['doc_id'];
                    $note  = $data['note'];
                    // This sets the alert to hidden (Resolved)
                    $pdo->prepare("UPDATE documents SET is_resolved = 1, resolution_note = ? WHERE id = ?")->execute([$note, $docId]);

                    // NOTIFY SUCCESS
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Resolution Approved', 'Your resolution report was approved.', 'success')")
                        ->execute([$req['user_id']]);

                    $logger->log($adminId, 'APPROVED_RESOLUTION', "Approved resolution for Doc ID $docId");
                }
                // 5. EDIT DOCUMENT
                elseif ($req['request_type'] === 'EDIT_DOC') {
                    $docId = $req['target_id'];
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
                    if (array_key_exists('new_expiry_date', $data)) {
                        $updateCols[] = "expiry_date = ?";
                        $updateParams[] = $data['new_expiry_date'];
                    }
                    if (isset($data['move_to_emp_id'])) {
                        $updateCols[] = "employee_id = ?";
                        $updateParams[] = $data['move_to_emp_id'];
                    }

                    if (!empty($updateCols)) {
                        $updateCols[] = "updated_at = NOW()";
                        $updateCols[] = "updated_by = ?";
                        $updateParams[] = $req['user_id']; // The user who requested the change
                        $updateParams[] = $docId;
                        $sql = "UPDATE documents SET " . implode(', ', $updateCols) . " WHERE id = ?";
                        $pdo->prepare($sql)->execute($updateParams);
                    }

                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Document Edit Approved', ?, 'success')")
                        ->execute([$req['user_id'], "Your edit for document '" . ($data['original_details']['original_name'] ?? $docId) . "' was approved."]);

                    $logger->log($adminId, 'APPROVED_EDIT_DOC', "Approved edit for Doc ID: " . $docId);
                }

                // [MHI 5.2] ARCHIVE REQUEST (Retention) - Do not delete
                $pdo->prepare("UPDATE requests SET status = 'APPROVED', admin_comment = ? WHERE id = ?")->execute(["Approved by " . $_SESSION['username'], $current_req_id]);
                $pdo->commit(); // [DATA INTEGRITY] Commit All Changes
                $successCount++;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $failCount++;
                $failMsgs[] = "Req #" . $current_req_id . ": " . $e->getMessage();
            }
        } elseif ($coreAction === 'reject') {
            try {
                $pdo->beginTransaction(); // [DATA INTEGRITY] Start Transaction

                // [NEW] REJECTION LOGIC WITH NOTE
                $msgTitle = "Request Rejected";
                $msgBody  = "Your request (" . $req['request_type'] . ") was rejected.";

                if (!empty($reject_reason)) {
                    $msgBody .= "\n\nReason: " . $reject_reason;
                }

                // [LOGICAL FIX] Cleanup physical files to prevent orphans on rejection
                if ($req['request_type'] === 'UPLOAD_DOC') {
                    $vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;
                    $filePath = $vaultPath . basename($data['file_path'] ?? '');
                    if (!empty($data['file_path']) && file_exists($filePath)) {
                        @unlink($filePath);
                    }
                } elseif ($req['request_type'] === 'ADD_EMPLOYEE') {
                    if (!empty($data['avatar_path']) && $data['avatar_path'] !== 'default.png') {
                        $avatarPath = dirname(__DIR__) . '/uploads/avatars/' . basename($data['avatar_path']);
                        if (file_exists($avatarPath)) @unlink($avatarPath);
                    }
                } elseif ($req['request_type'] === 'EDIT_PROFILE') {
                    $oldIdStmt = $pdo->prepare("SELECT avatar_path FROM employees WHERE id = ?");
                    $oldIdStmt->execute([$req['target_id']]);
                    $oldEmp = $oldIdStmt->fetch();
                    // Only delete if they actually uploaded a NEW avatar
                    if (!empty($data['avatar_path']) && $data['avatar_path'] !== 'default.png' && (!$oldEmp || $oldEmp['avatar_path'] !== $data['avatar_path'])) {
                        $avatarPath = dirname(__DIR__) . '/uploads/avatars/' . basename($data['avatar_path']);
                        if (file_exists($avatarPath)) @unlink($avatarPath);
                    }
                }

                // Insert Notification
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'danger')")
                    ->execute([$req['user_id'], $msgTitle, $msgBody]);

                // [MHI 5.2] ARCHIVE REQUEST (Retention) - Store raw reason in DB
                $pdo->prepare("UPDATE requests SET status = 'REJECTED', admin_comment = ? WHERE id = ?")->execute([$reject_reason, $current_req_id]);

                $pdo->commit();
                $logger->log($adminId, 'REJECTED_REQUEST', "Rejected request: " . $req['request_type']);
                $successCount++;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $failCount++;
                $failMsgs[] = "Req #" . $current_req_id . ": " . $e->getMessage();
            }
        }
    }

    // Construct Final Status Message
    if ($isBulk || count($reqIds) > 1) {
        $msgClass = $failCount > 0 ? "⚠️" : "✅";
        $msg = "$msgClass Processed $successCount successfully.";
        if ($failCount > 0) {
            $msg .= " Failed $failCount. " . implode(" | ", $failMsgs);
        }
    } else {
        if ($failCount > 0) {
            $msg = "❌ Error: " . $failMsgs[0];
        } else {
            $msg = "✅ Request " . ucfirst($coreAction) . "d Successfully.";
        }
    }

    header("Location: admin_approval.php?msg=" . rawurlencode($msg) . "&tab=" . rawurlencode($tab));
    exit;
}

/// FETCH REQUESTS (Filter by PENDING)
$newHires = in_array('hires', $enabledWidgets) ? $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE request_type='ADD_EMPLOYEE' AND status='PENDING'")->fetchAll() : [];
$edits    = in_array('edits', $enabledWidgets) ? $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE request_type='EDIT_PROFILE' AND status='PENDING'")->fetchAll() : [];
$docs     = in_array('docs', $enabledWidgets) ? $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE request_type='UPLOAD_DOC' AND status='PENDING'")->fetchAll() : [];
$doc_edits = in_array('doc-edits', $enabledWidgets) ? $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE request_type='EDIT_DOC' AND status='PENDING'")->fetchAll() : [];
$tickets  = in_array('tickets', $enabledWidgets) ? $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE request_type='RESOLVE_ALERT' AND status='PENDING'")->fetchAll() : [];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Approvals</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="uploads/tesp-logo.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
</head>

<body class="bg-body-tertiary">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white">Approval Center</span>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if (isset($_GET['msg'])): ?>
            <?php
            // Auto-detect error messages to style them red
            $msgClass = (stripos($_GET['msg'], 'Error') !== false || stripos($_GET['msg'], 'CANNOT') !== false) ? 'alert-danger' : 'alert-success';
            ?>
            <div class='alert <?php echo $msgClass; ?>'><?php echo htmlspecialchars($_GET['msg']); ?></div>
            <script>
                // Clear message on load
                if (window.history.replaceState) {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('msg');
                    window.history.replaceState(null, '', url);
                }
            </script>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="approvalTabs" role="tablist">
                    <?php
                    $widgetConfig = [
                        'hires' => ['label' => 'New Hires', 'count' => count($newHires), 'data' => $newHires],
                        'edits' => ['label' => 'Profile Edits', 'count' => count($edits), 'data' => $edits],
                        'docs' => ['label' => 'Documents', 'count' => count($docs), 'data' => $docs],
                        'doc-edits' => ['label' => 'Doc Edits', 'count' => count($doc_edits), 'data' => $doc_edits],
                        'tickets' => ['label' => 'Resolutions', 'count' => count($tickets), 'data' => $tickets],
                    ];
                    $isFirst = true;
                    foreach ($widgetConfig as $key => $widget) {
                        if (in_array($key, $enabledWidgets)) {
                            $activeClass = $isFirst ? 'active' : '';
                            $ariaSelected = $isFirst ? 'true' : 'false';
                            $isFirst = false;
                            echo "<li class='nav-item' role='presentation'>";
                            echo "<button class='nav-link $activeClass fw-bold' id='tab-btn-$key' data-bs-toggle='tab' data-bs-target='#tab-$key' type='button' role='tab' aria-controls='tab-$key' aria-selected='$ariaSelected'>{$widget['label']} <span class='badge bg-secondary rounded-pill ms-1'>{$widget['count']}</span></button></li>";
                        }
                    }
                    ?>
                </ul>
            </div>

            <div class="card-body p-0 table-responsive">
                <div class="tab-content">
                    <?php
                    $isFirst = true;
                    foreach ($widgetConfig as $key => $widget) {
                        if (in_array($key, $enabledWidgets)) {
                            $activeClass = $isFirst ? 'show active' : '';
                            $isFirst = false;
                            $dataType = match ($key) {
                                'hires' => 'hire',
                                'edits' => 'edit',
                                'docs' => 'doc',
                                'doc-edits' => 'doc_edit',
                                'tickets' => 'ticket',
                            };
                            echo "<div class='tab-pane fade $activeClass' id='tab-$key' role='tabpanel' aria-labelledby='tab-btn-$key'>";
                            renderTable($widget['data'], $dataType);
                            echo "</div>";
                        }
                    }
                    ?>
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
                        <textarea name="reject_reason" class="form-control" rows="3" placeholder="e.g. Photo is blurry, please retake." required maxlength="255" oninput="this.value = this.value.replace(/[<>]/g, '')"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Hidden form used for single-row approve/reject actions -->
    <form id="singleActionForm" method="POST" style="display:none;">
        <input type="hidden" id="single_req_id" name="req_id" value="">
        <input type="hidden" id="single_tab_name" name="tab_name" value="">
        <input type="hidden" id="single_action" name="action" value="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    </form>

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

        echo '<form method="POST" id="bulkForm_' . $type . '">';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token']) . '">';
        echo '<input type="hidden" name="tab_name" value="' . $tabName . '">';
        echo '<input type="hidden" name="action" id="bulkAction_' . $type . '" value="">';
        echo '<div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-body-tertiary">';
        echo '<div><button type="button" class="btn btn-sm btn-success me-2 fw-bold shadow-sm" onclick="submitBulk(\'' . $type . '\', \'bulk_approve\')"><i class="bi bi-check-all"></i> Approve Selected</button>';
        echo '<button type="button" class="btn btn-sm btn-danger fw-bold shadow-sm" onclick="submitBulk(\'' . $type . '\', \'bulk_reject\')"><i class="bi bi-x-square"></i> Reject Selected</button></div>';
        echo '</div>';
        echo '<table class="table table-hover mb-0 align-middle">';
        echo '<thead><tr><th style="width: 40px;" class="text-center"><input type="checkbox" class="form-check-input" onclick="toggleAll(this, \'' . $type . '\')"></th><th>Date</th><th>User</th><th>Summary</th><th class="text-end">Actions</th></tr></thead><tbody class="table-group-divider">';

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
            <td><input type='checkbox' name='req_ids[]' value='{$r['id']}' class='form-check-input bulk-check-{$type}'></td>
            <td>" . date('M d, H:i', strtotime($r['created_at'])) . "</td>
            <td><span class='badge bg-secondary'>{$r['username']}</span></td>
            <td>$summary</td>
            <td class='text-end'>
                <button type='button' class='btn btn-sm btn-info text-white me-2' onclick='openPreview($jsonData, \"$type\", {$r['id']})' title='View Details'><i class='bi bi-eye'></i> View</button>
                <button type='button' class='btn btn-sm btn-success' title='Approve' onclick='submitSingle({$r['id']}, \"$tabName\", \"approve\")'><i class='bi bi-check-lg'></i></button>
                <button type='button' class='btn btn-sm btn-danger' onclick='openRejectModal({$r['id']}, \"$tabName\")' title='Reject with Note'><i class='bi bi-x-lg'></i></button>
            </td>
        </tr>";
        }
        echo '</tbody></table></form>';
    }
    ?>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="dark_mode.js"></script>
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

        // --- SINGLE ACTIONS ---
        function submitSingle(reqId, tabName, action) {
            document.getElementById('single_req_id').value = reqId;
            document.getElementById('single_tab_name').value = tabName;
            document.getElementById('single_action').value = action;
            document.getElementById('singleActionForm').submit();
        }

        function openRejectModal(reqId, tabName) {
            document.getElementById('reject_req_id').value = reqId;
            document.getElementById('reject_tab_name').value = tabName;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('rejectModal')).show();
        }

        // --- BULK ACTIONS ---
        function toggleAll(source, type) {
            const checkboxes = document.querySelectorAll('.bulk-check-' + type);
            checkboxes.forEach(cb => cb.checked = source.checked);
        }

        function submitBulk(type, action) {
            const form = document.getElementById('bulkForm_' + type);
            const checkboxes = form.querySelectorAll('.bulk-check-' + type + ':checked');
            if (checkboxes.length === 0) {
                Swal.fire('No Selection', 'Please select at least one request by checking the boxes on the left.', 'warning');
                return;
            }

            if (action === 'bulk_reject') {
                Swal.fire({
                    title: 'Bulk Reject',
                    input: 'text',
                    inputLabel: 'Reason for Rejection (Optional)',
                    showCancelButton: true,
                    confirmButtonText: 'Reject All',
                    confirmButtonColor: '#dc3545'
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.getElementById('bulkAction_' + type).value = action;
                        const reasonInput = document.createElement('input');
                        reasonInput.type = 'hidden';
                        reasonInput.name = 'reject_reason';
                        reasonInput.value = result.value || '';
                        form.appendChild(reasonInput);
                        form.submit();
                    }
                });
            } else {
                Swal.fire({
                    title: 'Bulk Approve',
                    text: `Are you sure you want to approve ${checkboxes.length} request(s)?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Approve All',
                    confirmButtonColor: '#198754'
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.getElementById('bulkAction_' + type).value = action;
                        form.submit();
                    }
                });
            }
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
                        <h5 class="text-body"><i class="bi bi-clipboard-check"></i> Resolution Report</h5>
                        <hr>
                        <p class="mb-1 text-primary fw-bold small text-uppercase">Action Taken${data.doc_name ? ' for ' + escapeHtml(data.doc_name) : ''}:</p>
                        <p class="fs-5 fw-bold text-body">"${escapeHtml(data.note)}"</p>
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
                    content += `<img src="${filePath}" style="max-width:100%; max-height:400px; display:block; margin:0 auto;" onerror="this.onerror=null; this.outerHTML='<div class=\\'alert alert-secondary text-center my-3\\'><i class=\\'bi bi-image fs-1 text-muted\\'></i><br>Image preview unavailable.</div>';">`;
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
                if (data.new_expiry_date !== undefined && data.new_expiry_date !== orig.expiry_date) {
                    hasChanges = true;
                    changesHtml += `<li class="list-group-item"><strong>Expiration Date:</strong><br><del class="text-danger">${escapeHtml(orig.expiry_date || 'None')}</del><br><ins class="text-success">${escapeHtml(data.new_expiry_date || 'None')}</ins></li>`;
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
                            <h6 class="text-body fw-bold"><i class="bi bi-chat-left-text-fill me-2"></i> Note from Staff:</h6>
                            <p class="mb-0 text-body fs-6">"${escapeHtml(data.request_note)}"</p>
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
                        content += `<tr><th class="table-active w-25">${escapeHtml(label)}</th><td>${escapeHtml(value.toString())}</td></tr>`;
                    }
                }
                content += '</table>';
            }

            modalBody.innerHTML = content;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal')).show();
        }
    </script>
</body>

</html>