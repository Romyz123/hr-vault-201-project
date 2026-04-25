<?php
// public/expiry_report.php
require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: Admin & HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    die("ACCESS DENIED");
}

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$security = new Security($pdo);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$msg = "";

// [NEW] Handle Quick Date Adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_date') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Failed");
    }
    $docId = (int)$_POST['doc_id'];
    $newDate = $_POST['new_date'];

    $stmt = $pdo->prepare("UPDATE documents SET expiry_date = ?, is_resolved = 0, updated_at = NOW() WHERE id = ?");
    if ($stmt->execute([$newDate, $docId])) {
        $msg = "✅ Expiry date updated successfully.";
        // [FIX] Instantiate logger properly to record the action
        require_once '../src/Logger.php';
        $logger = new Logger($pdo);
        $logger->log($_SESSION['user_id'], 'UPDATE_DOC_EXPIRY', "Updated expiry date for Doc ID: $docId to $newDate");
    }
}

// [NEW] Handle Manual 90-Day Cleanup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cleanup') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Failed");
    }
    $cleanupStmt = $pdo->prepare("UPDATE documents SET expiry_date = NULL, is_resolved = 0, resolution_note = NULL WHERE is_resolved = 1 AND expiry_date < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $cleanupStmt->execute();
    $cleanedCount = $cleanupStmt->rowCount();
    if (isset($logger)) {
        $logger->log($_SESSION['user_id'], 'CLEANUP_RESOLVED_EXPIRY', "Cleaned up $cleanedCount old resolved expiry alerts.");
    }
    $msg = "✅ Cleaned up $cleanedCount old resolved expiry alerts.";
}

// Capture filters for redirect persistence
$days = isset($_REQUEST['days']) ? (int)$_REQUEST['days'] : 30;
$dept = isset($_REQUEST['dept']) ? $_REQUEST['dept'] : '';
$statusFilter = isset($_REQUEST['status']) ? $_REQUEST['status'] : 'pending';
$dateFrom = $_REQUEST['date_from'] ?? '';
$dateTo = $_REQUEST['date_to'] ?? '';

// [NEW] Handle Bulk Resolve Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_resolve') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF Failed");
    }

    $docIds = isset($_POST['doc_ids']) && is_array($_POST['doc_ids']) ? $_POST['doc_ids'] : [];
    $resolutionNote = trim($_POST['resolution_note'] ?? '');

    if (empty($docIds)) {
        $msg = "⚠️ No documents selected for bulk resolution.";
        $msgType = 'warning';
    } elseif (empty($resolutionNote)) {
        $msg = "❌ Resolution note is required for bulk resolve.";
        $msgType = 'danger';
    } elseif (strlen($resolutionNote) > 1000) {
        $msg = "❌ Resolution note is too long (Max 1000 chars).";
        $msgType = 'danger';
    } else {
        // Sanitize doc IDs
        $docIds = array_filter($docIds, 'is_numeric');
        $docIds = array_map('intval', $docIds);

        if (!empty($docIds)) {
            $placeholders = implode(',', array_fill(0, count($docIds), '?'));
            $stmt = $pdo->prepare("UPDATE documents SET is_resolved = 1, resolution_note = ?, updated_at = NOW() WHERE id IN ($placeholders)");
            $params = array_merge([$resolutionNote], $docIds);
            $stmt->execute($params);
            $resolvedCount = $stmt->rowCount();

            if (isset($logger)) {
                $logger->log($_SESSION['user_id'], 'BULK_RESOLVE_EXPIRY', "Bulk resolved $resolvedCount expiry alerts.");
            }
            $msg = "✅ Successfully resolved $resolvedCount document alerts.";
            $msgType = 'success';
        }
    }
    header("Location: expiry_report.php?msg=" . urlencode($msg) . "&type=" . urlencode($msgType ?? 'info') . "&days=" . urlencode($days) . "&dept=" . urlencode($dept) . "&status=" . urlencode($statusFilter) . "&date_from=" . urlencode($dateFrom) . "&date_to=" . urlencode($dateTo));
    exit;
}

// 3. DATABASE QUERY
// "Show me files that expire between TODAY and (Today + X Days)"
$targetDate = date('Y-m-d', strtotime("+$days days"));
$today = date('Y-m-d');

$sql = "SELECT d.*, e.first_name, e.last_name, e.dept, e.emp_id AS real_emp_id 
        FROM documents d
        JOIN employees e ON d.employee_id = e.emp_id
        WHERE d.expiry_date IS NOT NULL
        AND d.deleted_at IS NULL
        AND e.deleted_at IS NULL
        AND e.status = 'Active'";

if (!empty($dateFrom) && !empty($dateTo)) {
    $sql .= " AND d.expiry_date BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
} else {
    $sql .= " AND d.expiry_date <= ?";
    $params = [$targetDate];
}

if ($dept) {
    $sql .= " AND e.dept = ?";
    $params[] = $dept;
}

if ($statusFilter === 'pending') {
    $sql .= " AND d.is_resolved = 0";
} elseif ($statusFilter === 'resolved') {
    $sql .= " AND d.is_resolved = 1";
}

$sql .= " ORDER BY d.expiry_date ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Expiry Forecast | HR System</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <link rel="icon" type="image/png" href="assets/tesp-logo.png?v=4">
    <!-- [FIX] Include SweetAlert2 for the adjustment modal to work -->
    <script src="assets/sweetalert2.all.min.js"></script>
    <style>
        /* Yellow for coming soon */
        @media print {
            .no-print {
                display: none !important;
            }

            .card {
                border: none !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>

<body class="bg-body-tertiary">

    <nav class="navbar navbar-dark bg-dark mb-4 no-print">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white"><i class="bi bi-binoculars-fill text-danger"></i> Expiry Forecast</span>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        <?php if ($msg): ?>
            <div class="alert alert-success shadow-sm mb-4 no-print"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div>
                <p class="text-muted mb-0">Projected expirations for the next <strong><?php echo htmlspecialchars($days); ?> days</strong>.</p>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <form method="POST" class="m-0" onsubmit="return confirm('Are you sure you want to permanently clear all resolved alerts older than 90 days?');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="cleanup">
                    <input type="hidden" name="days" value="<?php echo h($days); ?>">
                    <input type="hidden" name="dept" value="<?php echo h($dept); ?>">
                    <input type="hidden" name="status" value="<?php echo h($statusFilter); ?>">
                    <input type="hidden" name="date_from" value="<?php echo h($dateFrom); ?>">
                    <input type="hidden" name="date_to" value="<?php echo h($dateTo); ?>">
                    <button type="submit" class="btn btn-warning shadow-sm fw-bold"><i class="bi bi-magic"></i> Clean Old Alerts</button>
                </form>
                <button type="button" id="bulkDownloadBtn" class="btn btn-primary shadow-sm fw-bold" style="display:none;" onclick="submitBulkDownload()">
                    <i class="bi bi-file-earmark-zip-fill"></i> Download Selected (<span id="selectedDocCountDl">0</span>)
                </button>
                <button type="button" id="bulkResolveBtn" class="btn btn-success shadow-sm fw-bold" style="display:none;" onclick="submitBulkResolve()">
                    <i class="bi bi-check-circle-fill"></i> Resolve Selected (<span id="selectedDocCount">0</span>)
                </button>
                <form class="d-flex gap-2">
                    <div class="input-group input-group-sm shadow-sm">
                        <span class="input-group-text bg-light text-muted">Range</span>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>" title="Start Date">
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>" title="End Date">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                    </div>

                    <select name="status" class="form-select shadow-sm" onchange="this.form.submit()">
                        <option value="all" <?php if ($statusFilter == 'all') echo 'selected'; ?>>All Alerts</option>
                        <option value="pending" <?php if ($statusFilter == 'pending') echo 'selected'; ?>>Pending Actions</option>
                        <option value="resolved" <?php if ($statusFilter == 'resolved') echo 'selected'; ?>>Resolved</option>
                    </select>
                    <select name="dept" class="form-select shadow-sm" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php
                        $depts = ['ADMIN', 'HMS', 'RAS', 'TRS', 'LMS', 'DOS', 'SQP', 'CTS', 'SIGCOM', 'PSS', 'OCS', 'BFS', 'WHS', 'GUNJIN'];
                        foreach ($depts as $d) echo "<option value='$d' " . ($dept == $d ? 'selected' : '') . ">$d</option>";
                        ?>
                    </select>
                    <select name="days" class="form-select shadow-sm" onchange="this.form.submit()">
                        <option value="30" <?php if ($days == 30) echo 'selected'; ?>>Next 30 Days</option>
                        <option value="60" <?php if ($days == 60) echo 'selected'; ?>>Next 60 Days</option>
                        <option value="90" <?php if ($days == 90) echo 'selected'; ?>>Next 90 Days</option>
                    </select>
                </form>
                <button onclick="window.print()" class="btn btn-dark shadow-sm"><i class="bi bi-printer-fill"></i> Print List</button>
            </div>
        </div>

        <form id="bulkResolveForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="bulk_resolve">
            <input type="hidden" name="resolution_note" id="bulkResolutionNote">
            <input type="hidden" name="days" value="<?php echo htmlspecialchars($days, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="dept" value="<?php echo htmlspecialchars($dept, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="card shadow-sm border-0">
                <div class="card-body p-0">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" class="form-check-input" id="selectAllDocs"></th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Document</th>
                                <th class="no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($docs)): ?>
                                <tr>
                                    <td colspan="7" class="text-center p-5 text-muted">
                                        <i class="bi bi-check-circle fs-1 text-success"></i><br>
                                        <span class="fw-bold mt-2 d-block">No expirations found in this range!</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($docs as $doc):
                                    $timeLeft = floor((strtotime($doc['expiry_date']) - time()) / 86400);
                                    // Determine Color: Expired = Red, Warning = Yellow
                                    $rowClass = ($timeLeft < 0) ? 'table-danger' : '';
                                    $statusLabel = ($timeLeft < 0) ? 'EXPIRED' : $timeLeft . ' days left';
                                    $badgeColor = ($timeLeft < 0) ? 'bg-danger' : 'bg-warning text-dark';
                                ?>
                                    <tr class="<?php echo $rowClass; ?>">
                                        <td><input type="checkbox" name="doc_ids[]" value="<?php echo $doc['id']; ?>" class="form-check-input doc-checkbox" onchange="updateSelectionCount()"></td>
                                        <td class="fw-bold text-danger"><?php echo htmlspecialchars($doc['expiry_date']); ?></td>
                                        <td><span class="badge <?php echo $badgeColor; ?>"><?php echo $statusLabel; ?></span></td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($doc['last_name'] . ', ' . $doc['first_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($doc['real_emp_id']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($doc['dept']); ?></td>
                                        <td>
                                            <i class="bi bi-file-earmark-text me-1"></i>
                                            <?php echo htmlspecialchars($doc['original_name']); ?>
                                            <?php if ($doc['is_resolved'] == 1 && !empty($doc['resolution_note'])): ?>
                                                <div class="text-success small mt-1 fw-bold"><i class="bi bi-check-circle-fill"></i> Resolved: <?php echo htmlspecialchars($doc['resolution_note']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="no-print">
                                            <div class="btn-group btn-group-sm shadow-sm">
                                                <a href="view_doc.php?id=<?php echo $doc['file_uuid']; ?>"
                                                    class="btn btn-primary" target="_blank" title="View Document Directly">
                                                    <i class="bi bi-file-earmark-text"></i>
                                                </a>
                                                <a href="index.php?search=<?php echo urlencode($doc['real_emp_id']); ?>&resolve_doc=<?php echo $doc['id']; ?>"
                                                    class="btn btn-outline-primary" target="_blank" title="View in Dashboard Context">
                                                    <i class="bi bi-speedometer2"></i>
                                                </a>
                                                <a href="upload_form.php?emp_id=<?php echo urlencode($doc['real_emp_id']); ?>&category=<?php echo urlencode($doc['category']); ?>"
                                                    class="btn btn-success" title="Renew Document (Re-upload)">
                                                    <i class="bi bi-arrow-repeat"></i>
                                                </a>
                                                <button type="button" class="btn btn-info text-white" title="Quick Adjust Date" onclick="editExpiryDate(<?php echo $doc['id']; ?>, '<?php echo $doc['expiry_date']; ?>')">
                                                    <i class="bi bi-calendar-event"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-info" title="Copy Reminder for Employee" onclick="copyExpiryReminder('<?php echo h($doc['first_name']); ?>', '<?php echo h($doc['original_name']); ?>', '<?php echo $doc['expiry_date']; ?>')">
                                                    <i class="bi bi-chat-left-text"></i>
                                                </button>
                                                <?php if ($timeLeft < 0): ?>
                                                    <a href="disciplinary.php?emp_id=<?php echo urlencode($doc['real_emp_id']); ?>&violation=Expired Document&rule=General Provisions&desc=The document '<?php echo urlencode($doc['original_name']); ?>' expired on <?php echo $doc['expiry_date']; ?> and has not been renewed."
                                                        class="btn btn-danger" title="Escalate to Disciplinary (NTE)">
                                                        <i class="bi bi-gavel"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
    </div>

    <!-- [NEW] Hidden form for date update -->
    <form id="updateDateForm" method="POST" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="update_date">
        <input type="hidden" name="doc_id" id="updateDateDocId">
        <input type="hidden" name="new_date" id="updateDateValue">
    </form>

    <!-- [NEW] Hidden form for bulk download -->
    <form id="bulkDownloadForm" action="api/bulk_download_expiry.php" method="POST" target="_blank" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <div id="bulkDownloadIdsContainer"></div>
    </form>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="assets/dark_mode.js"></script>
    <script>
        // [NEW] Bulk Resolve Logic
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('selectAllDocs');
            const docCheckboxes = document.querySelectorAll('.doc-checkbox');

            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    docCheckboxes.forEach(cb => {
                        cb.checked = this.checked;
                    });
                    updateSelectionCount();
                });
            }
            updateSelectionCount(); // Initial count on load
        });

        /**
         * [NEW] Quick Date Adjustment Modal
         */
        function editExpiryDate(id, current) {
            Swal.fire({
                title: 'Adjust Expiry Date',
                input: 'date',
                inputValue: current,
                showCancelButton: true,
                confirmButtonText: 'Save Change',
                inputValidator: (value) => {
                    if (!value) return 'Date is required!';
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('updateDateDocId').value = id;
                    document.getElementById('updateDateValue').value = result.value;
                    document.getElementById('updateDateForm').submit();
                }
            });
        }

        /**
         * [NEW] Copy pre-formatted reminder to clipboard
         */
        function copyExpiryReminder(name, docName, date) {
            const text = `Hi ${name},\n\nThis is a reminder from HR that your document "${docName}" expired/will expire on ${date}. Please process your renewal or submit an updated copy as soon as possible.\n\nThank you!`;
            navigator.clipboard.writeText(text).then(() => {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Reminder copied!',
                    showConfirmButton: false,
                    timer: 2000
                });
            });
        }

        function updateSelectionCount() {
            const checked = document.querySelectorAll('.doc-checkbox:checked');
            const checkedCount = checked.length;
            const bulkBtn = document.getElementById('bulkResolveBtn');
            const bulkDlBtn = document.getElementById('bulkDownloadBtn');
            const selectedCountSpan = document.getElementById('selectedDocCount');
            const selectedCountDlSpan = document.getElementById('selectedDocCountDl');

            if (checkedCount > 0) {
                selectedCountSpan.textContent = checkedCount;
                selectedCountDlSpan.textContent = checkedCount;
                bulkBtn.style.display = 'inline-block';
                bulkDlBtn.style.display = 'inline-block';
            } else {
                bulkBtn.style.display = 'none';
                bulkDlBtn.style.display = 'none';
            }
        }

        /**
         * [NEW] Bulk Download Logic
         */
        function submitBulkDownload() {
            const checked = document.querySelectorAll('.doc-checkbox:checked');
            const container = document.getElementById('bulkDownloadIdsContainer');
            container.innerHTML = '';

            checked.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'doc_ids[]';
                input.value = cb.value;
                container.appendChild(input);
            });

            document.getElementById('bulkDownloadForm').submit();

            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'info',
                title: 'Preparing ZIP...',
                showConfirmButton: false,
                timer: 3000
            });
        }

        function submitBulkResolve() {
            const checkedCount = document.querySelectorAll('.doc-checkbox:checked').length;
            if (checkedCount === 0) {
                Swal.fire('No Selection', 'Please select at least one document to resolve.', 'warning');
                return;
            }

            Swal.fire({
                title: `Resolve ${checkedCount} Alerts?`,
                input: 'textarea',
                inputLabel: 'Resolution Note',
                inputPlaceholder: 'e.g. Employee submitted new document, Issue no longer relevant...',
                inputValidator: (value) => {
                    if (!value) {
                        return 'You need to provide a resolution note!';
                    }
                    if (value.length > 1000) {
                        return 'Resolution note is too long (Max 1000 characters).';
                    }
                },
                showCancelButton: true,
                confirmButtonText: 'Yes, Resolve All',
                confirmButtonColor: '#198754'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('bulkResolutionNote').value = result.value;
                    document.getElementById('bulkResolveForm').submit();
                }
            });
        }

        // [NEW] Scroll Memory Logic
        const scrollKey = 'hr201_scroll_pos_' + window.location.pathname;
        window.addEventListener('beforeunload', () => {
            sessionStorage.setItem(scrollKey, window.scrollY);
        });

        const urlParamsForScroll = new URLSearchParams(window.location.search);
        if (urlParamsForScroll.has('msg') || urlParamsForScroll.has('days') || urlParamsForScroll.has('dept') || urlParamsForScroll.has('status')) {
            const savedPos = sessionStorage.getItem(scrollKey);
            if (savedPos) window.scrollTo(0, parseInt(savedPos));
        }
    </script>
</body>

</html>