<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php'; 
session_start();

// SECURITY: Admin/HR Only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'ADMIN' && $_SESSION['role'] !== 'HR')) {
    header("Location: index.php");
    exit;
}

// === HANDLE APPROVAL / REJECTION ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    $req_id  = $_POST['req_id'];
    $action  = $_POST['action'];
    $tab     = $_POST['tab_name']; 
    $adminId = $_SESSION['user_id'];

    // FETCH DETAILS
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ?");
    $stmt->execute([$req_id]);
    $req = $stmt->fetch();
    
    if ($req) {
        $data = json_decode($req['json_payload'], true); 
        $logger = new Logger($pdo); 

        if ($action === 'approve') {
            try {
                // 1. ADD EMPLOYEE
                if ($req['request_type'] === 'ADD_EMPLOYEE') {
                    // Pre-check for duplicate ID
                    $dupCheck = $pdo->prepare("SELECT status FROM employees WHERE emp_id = ?");
                    $dupCheck->execute([$data['emp_id']]);
                    if ($dupCheck->rowCount() > 0) {
                        $msg = "⚠️ CANNOT APPROVE: The ID '" . $data['emp_id'] . "' is already in use. Please REJECT this request.";
                        header("Location: admin_approval.php?msg=$msg&tab=$tab");
                        exit;
                    }
                    $cols = implode(", ", array_keys($data));
                    $vals = implode(", ", array_fill(0, count($data), "?"));
                    $pdo->prepare("INSERT INTO employees ($cols) VALUES ($vals)")->execute(array_values($data));
                    $logger->log($adminId, 'APPROVED_HIRE', "Approved New Employee: " . $data['first_name'] . " " . $data['last_name']);
                } 
                // 2. EDIT PROFILE
                elseif ($req['request_type'] === 'EDIT_PROFILE') {
                    $targetId = $req['target_id'];
                    $setParts = []; $values = [];
                    foreach ($data as $key => $val) {
                        $setParts[] = "$key = ?";
                        $values[] = $val;
                    }
                    $values[] = $targetId;
                    $sql = "UPDATE employees SET " . implode(', ', $setParts) . " WHERE id = ?";
                    $pdo->prepare($sql)->execute($values);
                    
                    // CRITICAL: Move files if ID changed
                    if (isset($data['emp_id'])) {
                        $oldIdStmt = $pdo->prepare("SELECT emp_id FROM employees WHERE id = ?");
                        $oldIdStmt->execute([$targetId]);
                        $oldEmp = $oldIdStmt->fetch();
                        if ($oldEmp && $oldEmp['emp_id'] !== $data['emp_id']) {
                            $pdo->prepare("UPDATE documents SET employee_id = ? WHERE employee_id = ?")->execute([$data['emp_id'], $oldEmp['emp_id']]);
                        }
                    }
                    $logger->log($adminId, 'APPROVED_EDIT', "Approved Profile Edit for ID: " . $req['target_id']);
                }
                // 3. UPLOAD DOCUMENT
                elseif ($req['request_type'] === 'UPLOAD_DOC') {
                    $sql = "INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, expiry_date, description, uploaded_by) 
                            VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?)";
                    $pdo->prepare($sql)->execute([
                        $data['employee_id'], $data['original_name'], $data['file_path'], 
                        $data['category'], $data['expiry_date'], $data['description'], $req['user_id']
                    ]);
                    $logger->log($adminId, 'APPROVED_DOC', "Approved Document: " . $data['original_name']);
                }
                // 4. RESOLVE ALERT (TICKET)
                elseif ($req['request_type'] === 'RESOLVE_ALERT') {
                    $docId = $data['doc_id'];
                    $note  = $data['note'];
                    // This sets the alert to hidden (Resolved)
                    $pdo->prepare("UPDATE documents SET is_resolved = 1, resolution_note = ? WHERE id = ?")->execute([$note, $docId]);
                    $logger->log($adminId, 'APPROVED_RESOLUTION', "Approved resolution for Doc ID $docId");
                }

                // DELETE REQUEST (Cleanup)
                $pdo->prepare("DELETE FROM requests WHERE id = ?")->execute([$req_id]);
                $msg = "Request Approved Successfully";

            } catch (Exception $e) {
                $msg = "Error: " . $e->getMessage();
            }

        } elseif ($action === 'reject') {
            // === DISREGARD TICKET ===
            // Deleting the request WITHOUT updating 'is_resolved' means the alert stays active.
            $pdo->prepare("DELETE FROM requests WHERE id = ?")->execute([$req_id]);
            $logger->log($adminId, 'REJECTED_REQUEST', "Rejected/Disregarded request type: " . $req['request_type']);
            $msg = "Request Rejected / Disregarded";
        }
    }

    header("Location: admin_approval.php?msg=$msg&tab=$tab");
    exit;
}

/// FETCH REQUESTS (Updated to LEFT JOIN to show requests even if user is deleted)
$newHires = $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE request_type='ADD_EMPLOYEE'")->fetchAll();
$edits    = $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE request_type='EDIT_PROFILE'")->fetchAll();
$docs     = $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE request_type='UPLOAD_DOC'")->fetchAll();

// THIS IS THE ONE FOR RESOLUTIONS:
$tickets  = $pdo->query("SELECT r.*, u.username FROM requests r LEFT JOIN users u ON r.user_id = u.id WHERE request_type='RESOLVE_ALERT'")->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Approvals</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php">⬅ Dashboard</a>
    <span class="navbar-text text-white">Approval Center</span>
  </div>
</nav>

<div class="container">
    <?php if(isset($_GET['msg'])) echo "<div class='alert alert-warning'>" . htmlspecialchars($_GET['msg']) . "</div>"; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <ul class="nav nav-tabs card-header-tabs" id="approvalTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" id="tab-btn-hires" data-bs-toggle="tab" data-bs-target="#tab-hires">New Hires (<?php echo count($newHires); ?>)</button></li>
                <li class="nav-item"><button class="nav-link" id="tab-btn-edits" data-bs-toggle="tab" data-bs-target="#tab-edits">Edits (<?php echo count($edits); ?>)</button></li>
                <li class="nav-item"><button class="nav-link" id="tab-btn-docs" data-bs-toggle="tab" data-bs-target="#tab-docs">Documents (<?php echo count($docs); ?>)</button></li>
                <li class="nav-item"><button class="nav-link text-primary fw-bold" id="tab-btn-tickets" data-bs-toggle="tab" data-bs-target="#tab-tickets">Resolutions (<?php echo count($tickets); ?>)</button></li>
            </ul>
        </div>

        <div class="card-body p-0">
            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab-hires"><?php renderTable($newHires, 'hire'); ?></div>
                <div class="tab-pane fade" id="tab-edits"><?php renderTable($edits, 'edit'); ?></div>
                <div class="tab-pane fade" id="tab-docs"><?php renderTable($docs, 'doc'); ?></div>
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

<?php
// HELPER FUNCTION TO RENDER TABLES
function renderTable($requests, $type) {
    if (count($requests) == 0) { echo "<div class='p-4 text-center text-muted'>No pending requests.</div>"; return; }
    
    // Map tab names
    $tabName = match($type) { 'hire' => 'hires', 'edit' => 'edits', 'doc' => 'docs', 'ticket' => 'tickets' };
    
    echo '<table class="table table-hover mb-0"><thead class="table-light"><tr><th>Date</th><th>User</th><th>Summary</th><th class="text-end">Actions</th></tr></thead><tbody>';
    
    foreach ($requests as $r) {
        $data = json_decode($r['json_payload'], true);
        $jsonData = htmlspecialchars($r['json_payload'], ENT_QUOTES, 'UTF-8');
        
        // Dynamic Summary
        if ($type == 'hire') $summary = "<strong>New Employee:</strong> " . $data['first_name'] . " " . $data['last_name'];
        elseif ($type == 'edit') $summary = "<strong>Update Profile:</strong> ID " . $r['target_id'];
        elseif ($type == 'doc') $summary = "<strong>File Upload:</strong> " . $data['original_name'];
        elseif ($type == 'ticket') {
            $summary = "<strong class='text-primary'>Resolution Report:</strong> " . substr($data['note'], 0, 50) . "...";
        }
        
        echo "<tr>
            <td>".date('M d, H:i', strtotime($r['created_at']))."</td>
            <td><span class='badge bg-secondary'>{$r['username']}</span></td>
            <td>$summary</td>
            <td class='text-end'>
                <button class='btn btn-sm btn-info text-white me-2' onclick='openPreview($jsonData, \"$type\")' title='View Details'><i class='bi bi-eye'></i> View</button>
                
                <form method='POST' class='d-inline'>
                    <input type='hidden' name='req_id' value='{$r['id']}'>
                    <input type='hidden' name='tab_name' value='$tabName'>
                    
                    <button name='action' value='approve' class='btn btn-sm btn-success' title='Approve'><i class='bi bi-check-lg'></i></button>
                    
                    <button name='action' value='reject' class='btn btn-sm btn-danger' title='Disregard / Reject'><i class='bi bi-x-lg'></i></button>
                </form>
            </td>
        </tr>";
    }
    echo '</tbody></table>';
}
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab');
    if (activeTab) {
        let btnId = '';
        if (activeTab === 'hires') btnId = 'tab-btn-hires';
        if (activeTab === 'edits') btnId = 'tab-btn-edits';
        if (activeTab === 'docs')  btnId = 'tab-btn-docs';
        if (activeTab === 'tickets') btnId = 'tab-btn-tickets';
        
        const triggerEl = document.getElementById(btnId);
        if (triggerEl) { new bootstrap.Tab(triggerEl).show(); }
    }
});

function openPreview(data, type) {
    let content = '';
    const modalBody = document.getElementById('modalContent');

    // === 1. IF IT IS A TICKET (RESOLUTION REPORT) ===
    if (type === 'ticket') {
        content += `<div class="alert alert-warning border-start border-5 border-warning shadow-sm">
                        <h5 class="text-dark"><i class="bi bi-clipboard-check"></i> Resolution Report</h5>
                        <hr>
                        <p class="mb-1 text-muted small">Staff Note:</p>
                        <p class="fs-5 fw-bold text-dark">"${data.note}"</p>
                        <hr>
                        <small class="text-muted"><i class="bi bi-info-circle"></i> If you approve this, the Dashboard Alert will be marked as Resolved and removed.</small>
                    </div>`;
    }
    // === 2. IF IT IS A DOCUMENT ===
    else if (type === 'doc') {
        let filePath = 'uploads/' + encodeURIComponent(data.file_path); 
        let fileExt = data.original_name.split('.').pop().toLowerCase();
        
        content += `<h5>File: ${data.original_name}</h5>
                    <p>Category: <span class="badge bg-primary">${data.category}</span></p>
                    <div class="alert alert-info p-2 mb-3"><strong>Notes:</strong><br>${data.description || 'None'}</div>`;
        
        if (fileExt === 'pdf') {
            content += `<object data="${filePath}" type="application/pdf" width="100%" height="500px"><p>Unable to display PDF. <a href="${filePath}" target="_blank">Download File</a></p></object>`;
        } else {
            content += `<img src="${filePath}" style="max-width:100%; max-height:400px; display:block; margin:0 auto;" onerror="this.src='../assets/error_image.png';">`;
        }
    } 
    // === 3. IF IT IS A PROFILE EDIT/ADD ===
    else {
        content += '<table class="table table-bordered table-sm">';
        for (const [key, value] of Object.entries(data)) {
            if(value && key !== 'avatar_path') {
                content += `<tr><th class="bg-light w-25">${key.toUpperCase()}</th><td>${value}</td></tr>`;
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