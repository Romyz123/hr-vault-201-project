<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY
if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die("Invalid ID");

// 2. AUTHORIZATION
$security = new Security($pdo);
if (!$security->canViewEmployee($_SESSION['user_id'], $id)) {
    http_response_code(403);
    die("Access Denied: You do not have permission to view this employee's history.");
}

// 3. FETCH DATA
$stmt = $pdo->prepare("SELECT emp_id, first_name, last_name, dept FROM employees WHERE id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch();
if (!$emp) die("Employee not found");

$histStmt = $pdo->prepare("SELECT * FROM employment_history WHERE employee_id = ? ORDER BY event_date DESC");
$histStmt->execute([$id]);
$history = $histStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. LOGO
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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Employment History - <?php echo htmlspecialchars($emp['last_name']); ?></title>
    <link rel="icon" href="uploads/tesp-logo.png?v=3" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }

            body {
                background: white;
            }

            .page {
                box-shadow: none;
                margin: 0;
                padding: 0;
            }
        }

        body {
            background: #eee;
        }

        .page {
            background: white;
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 1in;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .header img {
            height: 60px;
        }

        .header h2 {
            margin: 10px 0 0 0;
        }

        .header h4 {
            margin: 0;
            color: #555;
        }
    </style>
</head>

<body>
    <div class="no-print text-center py-3">
        <button onclick="window.print()" class="btn btn-primary">Print</button>
        <button onclick="window.close()" class="btn btn-secondary">Close</button>
    </div>
    <div class="page">
        <div class="header">
            <?php if ($logo_src): ?>
                <img src="<?php echo $logo_src; ?>" alt="Company Logo">
            <?php endif; ?>
            <h2>Employment History</h2>
            <h4><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?> (<?php echo htmlspecialchars($emp['emp_id']); ?>)</h4>
        </div>
        <table class="table table-bordered table-striped">
            <thead class="table-light">
                <tr>
                    <th>Event Date</th>
                    <th>Event / Title</th>
                    <th>Department</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">No history on file.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($history as $h): ?>
                        <tr>
                            <td><?php echo date('M d, Y', strtotime($h['event_date'])); ?></td>
                            <td><?php echo htmlspecialchars($h['event_title']); ?></td>
                            <td><?php echo htmlspecialchars($h['department']); ?></td>
                            <td class="small text-muted"><?php echo nl2br(htmlspecialchars($h['notes'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>

</html>