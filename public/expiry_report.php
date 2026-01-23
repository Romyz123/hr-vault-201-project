<?php
// public/expiry_report.php
require '../config/db.php';
session_start();

// 1. SECURITY: Admin & HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'HR'])) {
    die("ACCESS DENIED");
}

// 2. FILTERS (Default to 30 days if not set)
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
$dept = isset($_GET['dept']) ? $_GET['dept'] : '';

// 3. DATABASE QUERY
// "Show me files that expire between TODAY and (Today + X Days)"
$targetDate = date('Y-m-d', strtotime("+$days days"));
$today = date('Y-m-d');

$sql = "SELECT d.*, e.first_name, e.last_name, e.dept, e.emp_id AS real_emp_id 
        FROM documents d
        JOIN employees e ON d.employee_id = e.emp_id
        WHERE d.expiry_date IS NOT NULL 
        AND d.expiry_date <= ? 
        AND d.expiry_date >= ?
        AND d.is_resolved = 0";

$params = [$targetDate, $today];

if ($dept) {
    $sql .= " AND e.dept = ?";
    $params[] = $dept;
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .expired { background-color: #ffe6e6 !important; } /* Red for expired */
        .soon { background-color: #fff3cd !important; }    /* Yellow for coming soon */
        @media print {
            .no-print { display: none !important; }
            .card { border: none !important; box-shadow: none !important; }
        }
    </style>
</head>
<body class="bg-light p-4">

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h3 class="fw-bold text-danger"><i class="bi bi-binoculars-fill"></i> Expiry Forecast</h3>
            <p class="text-muted mb-0">Projected expirations for the next <strong><?php echo $days; ?> days</strong>.</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <form class="d-flex gap-2">
                <select name="dept" class="form-select shadow-sm" onchange="this.form.submit()">
                    <option value="">All Departments</option>
                    <?php 
                    $depts = ['ADMIN','HMS','RAS','TRS','LMS','DOS','SQP','CTS','SIGCOM','PSS','OCS','BFS','WHS','GUNJIN'];
                    foreach ($depts as $d) echo "<option value='$d' ".($dept==$d?'selected':'').">$d</option>"; 
                    ?>
                </select>
                <select name="days" class="form-select shadow-sm" onchange="this.form.submit()">
                    <option value="30" <?php if($days==30) echo 'selected'; ?>>Next 30 Days</option>
                    <option value="60" <?php if($days==60) echo 'selected'; ?>>Next 60 Days</option>
                    <option value="90" <?php if($days==90) echo 'selected'; ?>>Next 90 Days</option>
                </select>
            </form>
            <button onclick="window.print()" class="btn btn-dark shadow-sm"><i class="bi bi-printer-fill"></i> Print List</button>
            <a href="index.php" class="btn btn-secondary shadow-sm">Back</a>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-dark">
                    <tr>
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
                            <td colspan="6" class="text-center p-5 text-muted">
                                <i class="bi bi-check-circle fs-1 text-success"></i><br>
                                <span class="fw-bold mt-2 d-block">No expirations found in this range!</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($docs as $doc): 
                            $timeLeft = floor((strtotime($doc['expiry_date']) - time()) / 86400);
                            // Determine Color: Expired = Red, Warning = Yellow
                            $rowClass = ($timeLeft < 0) ? 'expired' : 'soon';
                            $statusLabel = ($timeLeft < 0) ? 'EXPIRED' : $timeLeft . ' days left';
                            $badgeColor = ($timeLeft < 0) ? 'bg-danger' : 'bg-warning text-dark';
                        ?>
                        <tr class="<?php echo $rowClass; ?>">
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
                            </td>
                            <td class="no-print">
                                <a href="index.php?search=<?php echo urlencode($doc['real_emp_id']); ?>" 
                                   class="btn btn-sm btn-primary shadow-sm" target="_blank">
                                   <i class="bi bi-arrow-right"></i> Fix
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>