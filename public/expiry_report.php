<?php
// public/expiry_report.php
require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: Admin & HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    die("ACCESS DENIED");
}

function h(string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$security = new Security($pdo);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$msg = "";

// ======================================================
// [SAFEGUARD] Automatically add missing columns to the tables
// ======================================================
try {
    // 1. Check 'documents' table
    $colsRaw = $pdo->query("SHOW COLUMNS FROM documents")->fetchAll(PDO::FETCH_ASSOC);
    $cols = [];
    foreach ($colsRaw as $c) {
        if (is_array($c) && isset($c['Field'])) {
            $cols[] = $c['Field'];
        }
    }

    if (!empty($cols)) {
        if (!in_array('expiry_date', $cols)) {
            if (in_array('expiration_date', $cols)) {
                $pdo->exec("ALTER TABLE documents CHANGE COLUMN expiration_date expiry_date DATE NULL DEFAULT NULL");
            } else {
                $pdo->exec("ALTER TABLE documents ADD COLUMN expiry_date DATE NULL DEFAULT NULL");
            }
        }
        if (!in_array('is_resolved', $cols)) {
            $pdo->exec("ALTER TABLE documents ADD COLUMN is_resolved TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('resolution_note', $cols)) {
            $pdo->exec("ALTER TABLE documents ADD COLUMN resolution_note TEXT NULL DEFAULT NULL");
        }
        if (!in_array('deleted_at', $cols)) {
            $pdo->exec("ALTER TABLE documents ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL");
        }
    }

    // 2. Check 'employees' table
    $empColsRaw = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_ASSOC);
    $empCols = [];
    foreach ($empColsRaw as $c) {
        if (is_array($c) && isset($c['Field'])) {
            $empCols[] = $c['Field'];
        }
    }
    if (!empty($empCols) && !in_array('deleted_at', $empCols)) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL");
    }
} catch (Exception $e) {
    // Silently catch if table structure check fails
}
// ======================================================

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

// LOGO PREPARATION FOR PRINT HEADER
$logo_paths = [
    __DIR__ . '/uploads/tesp-logo.png',
    __DIR__ . '/uploads/tesp logo 1.png',
    __DIR__ . '/assets/images/tesp-logo-1.png',
    __DIR__ . '/../uploads/tesp-logo.png',
    __DIR__ . '/../uploads/tesp logo 1.png'
];
$logo_src = '';
foreach ($logo_paths as $p) {
    if (file_exists($p)) {
        $mime = pathinfo($p, PATHINFO_EXTENSION) === 'png' ? 'image/png' : 'image/jpeg';
        $logo_src = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
        break;
    }
}
if (empty($logo_src)) {
    $logo_src = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="40"><text y="30" font-size="14" fill="#333">TES</text></svg>');
}

require 'header.php';
?>
<style>
    /* PROFESSIONAL PRINT CSS */
    @media print {
        @page {
            size: portrait;
            margin: 15mm 10mm;
        }

        body {
            background: white !important;
            color: #000 !important;
            font-size: 10pt;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .no-print,
        .navbar,
        .sidebar,
        .btn,
        .alert,
        form:not(#bulkResolveForm) {
            display: none !important;
        }

        .container-fluid {
            padding: 0 !important;
            max-width: 100% !important;
        }

        /* Official Print Header Formatting */
        .print-header {
            display: block !important;
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 12px;
        }

        .print-header img {
            max-height: 55px;
            width: auto;
            margin-bottom: 8px;
        }

        .card {
            border: none !important;
            box-shadow: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* Table Formatting for Print */
        .table {
            width: 100% !important;
            border-collapse: collapse !important;
            margin-bottom: 0 !important;
        }

        .table th,
        .table td {
            border: 1px solid #777 !important;
            padding: 6px 8px !important;
            font-size: 9.5pt !important;
            vertical-align: middle !important;
            background-color: transparent !important;
        }

        .table-dark th {
            background-color: #e9ecef !important;
            color: #000 !important;
            font-weight: bold !important;
            border: 1px solid #000 !important;
        }

        /* Highlight expired rows subtly in print */
        .table-danger td {
            background-color: #fdf2f2 !important;
        }

        .badge {
            border: 1px solid #333 !important;
            color: #000 !important;
            background-color: transparent !important;
            padding: 4px 6px !important;
        }

        .text-success {
            color: #000 !important;
            font-style: italic;
        }
    }
</style>

<div class="container-fluid px-4">
    <?php if ($msg): ?>
        <div class="alert alert-success shadow-sm mb-4 no-print"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <!-- --- START: PRINT HEADER (Hidden on Screen) --- -->
    <div class="print-header d-none d-print-block">
        <img src="<?php echo $logo_src; ?>" alt="Company Logo">
        <div style="font-size: 16pt; font-weight: bold; text-transform: uppercase; margin: 0;">TES Philippines, Inc.</div>
        <div style="font-size: 14pt; font-weight: bold; text-transform: uppercase; margin: 0; padding-top: 5px;">Document Expiry & Compliance Report</div>
        <p class="text-muted small mt-2 mb-0">
            <strong>Period:</strong> <?php echo (!empty($dateFrom) && !empty($dateTo)) ? h(date('M d, Y', strtotime($dateFrom))) . ' to ' . h(date('M d, Y', strtotime($dateTo))) : 'Next ' . h($days) . ' Days'; ?> |
            <strong>Department:</strong> <?php echo $dept ? h($dept) : 'All Departments'; ?> |
            <strong>Status:</strong> <?php echo ucfirst(h($statusFilter)); ?> |
            <strong>Generated:</strong> <?php echo date('F j, Y'); ?>
        </p>
    </div>
    <!-- --- END: PRINT HEADER --- -->

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
            <button onclick="window.print()" class="btn btn-dark shadow-sm"><i class="bi bi-printer-fill"></i> Print Report</button>
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
                            <th class="no-print" style="width: 40px;"><input type="checkbox" class="form-check-input" id="selectAllDocs"></th>
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
                                    <td class="no-print"><input type="checkbox" name="doc_ids[]" value="<?php echo $doc['id']; ?>" class="form-check-input doc-checkbox" onchange="updateSelectionCount()"></td>
                                    <td class="fw-bold text-danger" style="white-space: nowrap;"><?php echo date('M d, Y', strtotime($doc['expiry_date'])); ?></td>
                                    <td><span class="badge <?php echo $badgeColor; ?>"><?php echo $statusLabel; ?></span></td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($doc['last_name'] . ', ' . $doc['first_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($doc['real_emp_id']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($doc['dept']); ?></td>
                                    <td>
                                        <i class="bi bi-file-earmark-text me-1 no-print"></i>
                                        <strong><?php echo htmlspecialchars($doc['original_name']); ?></strong>
                                        <?php if ($doc['is_resolved'] == 1 && !empty($doc['resolution_note'])): ?>
                                            <div class="text-success small mt-1 fw-bold"><i class="bi bi-check-circle-fill no-print"></i> Resolved: <?php echo htmlspecialchars($doc['resolution_note']); ?></div>
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
<?php require 'footer.php'; ?>