<?php
// public/print_recruitment.php
require '../config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) die("Access Denied");

// Filters
$filterStatus = $_GET['status'] ?? '';
$filterMonth  = $_GET['month'] ?? '';
$filterWeek   = $_GET['week'] ?? '';

$sql = "SELECT * FROM candidates WHERE 1=1";
$params = [];
if ($filterStatus) {
    $sql .= " AND status = ?";
    $params[] = $filterStatus;
}
if ($filterMonth) {
    $sql .= " AND DATE_FORMAT(application_date, '%Y-%m') = ?";
    $params[] = $filterMonth;
}
if ($filterWeek) {
    if (!preg_match('/^\d{4}-W?\d{1,2}$/', $filterWeek)) {
        // Invalid format, skip week filter
    } else {
        $year = (int)substr($filterWeek, 0, 4);
        $week = (int)preg_replace('/^\d{4}-W?/', '', $filterWeek);
        if ($week >= 1 && $week <= 53) {
            $dto = new DateTime();
            $dto->setISODate($year, $week);
            $startDate = $dto->format('Y-m-d');
            $dto->modify('+6 days');
            $endDate = $dto->format('Y-m-d');
            $sql .= " AND application_date BETWEEN ? AND ?";
            $params[] = $startDate;
            $params[] = $endDate;
        }
    }
}
$sql .= " ORDER BY application_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$candidates = $stmt->fetchAll();

// [FIX] Standardized logo path for embedding in print/word documents
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
    $logo_src = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mO8WQ8AAn0BbYpM8nsAAAAASUVORK5CYII=';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Recruitment Report</title>
    <link rel="icon" href="assets/tesp-logo.png?v=4" type="image/png">
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .header h2 {
            margin: 0;
            text-transform: uppercase;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 6px;
            text-align: left;
        }

        th {
            background-color: #f0f0f0;
        }

        .status-rejected {
            color: red;
            font-weight: bold;
        }

        .status-hired {
            color: green;
            font-weight: bold;
        }

        @media print {
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body>

    <div class="header">
        <img src="<?php echo $logo_src; ?>" style="height: 60px; display: block; margin: 0 auto 10px auto;">
        <h2>Recruitment Pipeline Report</h2>
    </div>

    <div class="no-print" style="margin-bottom: 20px; text-align: center;">
        <p>
            Generated on: <?php echo date('F d, Y'); ?><br>
            Filter: <?php echo $filterStatus ? htmlspecialchars($filterStatus) : 'All Status'; ?> |
            Month: <?php echo $filterMonth ? date('F Y', strtotime($filterMonth)) : 'All Time'; ?>
            <?php if ($filterWeek) echo " | Week: " . htmlspecialchars($filterWeek); ?>
        </p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date Applied</th>
                <th>Candidate Name</th>
                <th>Position</th>
                <th>Contact</th>
                <th>Status</th>
                <th>Notes / Rejection Reason</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($candidates as $c):
                $class = '';
                if ($c['status'] == 'Rejected') $class = 'status-rejected';
                if ($c['status'] == 'Hired') $class = 'status-hired';
            ?>
                <tr>
                    <td><?php echo date('M d, Y', strtotime($c['application_date'])); ?></td>
                    <td><?php echo htmlspecialchars($c['last_name'] . ', ' . $c['first_name']); ?></td>
                    <td><?php echo htmlspecialchars($c['position_applied']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($c['phone_number']); ?><br>
                        <?php echo htmlspecialchars($c['email']); ?>
                    </td>
                    <td class="<?php echo $class; ?>"><?php echo htmlspecialchars($c['status']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($c['notes'] ?? ''); ?>
                        <?php if (!empty($c['rejection_reason'])) echo "<br><strong>Reason:</strong> " . htmlspecialchars($c['rejection_reason']); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</body>

</html>