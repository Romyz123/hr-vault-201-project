<?php
// ======================================================
// [FILE] public/export_activity_pdf.php
// [PURPOSE] Professional Audit Report with Statistics
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();
checkSessionTimeout($pdo);

// 1. SECURITY
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['ADMIN', 'MANAGER'])) {
    die("Unauthorized Access.");
}

// 2. GET FILTERS (Mirroring activity_logs.php logic)
$filter = $_GET['filter'] ?? 'ALL';
$search = trim($_GET['search'] ?? '');
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';

// 3. FETCH SUMMARY STATISTICS
$statsToday = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn() ?: 0;
$statsMonth = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn() ?: 0;

$topContributor = ['name' => 'System', 'count' => 0];
try {
    $topStmt = $pdo->query("SELECT u.username, COUNT(a.id) as log_count 
                            FROM activity_logs a 
                            JOIN users u ON a.user_id = u.id 
                            GROUP BY a.user_id 
                            ORDER BY log_count DESC LIMIT 1");
    $rowTop = $topStmt->fetch();
    if ($rowTop) {
        $topContributor = ['name' => $rowTop['username'], 'count' => $rowTop['log_count']];
    }
} catch (Exception $e) {
}

// 4. DATA QUERY
$where = ['1=1'];
$params = [];

if ($filter === 'SECURITY') {
    $where[] = "a.action IN ('ADMIN_PASSWORD_RESET', 'USER_UNLOCK', 'LOGIN_LOCKED', 'ACCOUNT_LOCKOUT', 'USER_RESET_2FA', 'LOGIN_FAILED', 'LOGIN_FAIL_2FA', 'OTP_FAIL_LIMIT')";
} elseif ($filter === 'LOGINS') {
    $where[] = "(a.action LIKE 'LOGIN%' OR a.action = 'LOGOUT')";
} elseif ($filter === 'SYSTEM') {
    $where[] = "(a.action LIKE '%BACKUP%' OR a.action LIKE '%SETTINGS%')";
}

if ($dateFrom) {
    $where[] = "a.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $where[] = "a.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}
if ($search !== '') {
    $search = preg_replace('/[^a-zA-Z0-9\-_ ,]/', '', $search);
    $where[] = "(u.username LIKE ? OR a.action LIKE ? OR a.details LIKE ? OR a.ip_address LIKE ?)";
    $t = "%$search%";
    array_push($params, $t, $t, $t, $t);
}

$whereSql = implode(' AND ', $where);
$sql = "SELECT a.*, u.username FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id WHERE $whereSql ORDER BY a.created_at DESC LIMIT 1000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$logo_paths = [__DIR__ . '/uploads/tesp-logo.png', __DIR__ . '/assets/images/tesp-logo-1.png'];
$logo_src = '';
foreach ($logo_paths as $p) {
    if (file_exists($p)) {
        $logo_src = 'data:image/png;base64,' . base64_encode(file_get_contents($p));
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Audit Report - <?= date('Y-m-d') ?></title>
    <style>
        body {
            font-family: "Helvetica", Arial, sans-serif;
            font-size: 10pt;
            color: #333;
            line-height: 1.4;
            padding: 15mm;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #2a5298;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .logo {
            height: 60px;
        }

        .report-title {
            text-align: right;
        }

        .report-title h1 {
            margin: 0;
            color: #2a5298;
            font-size: 20pt;
            text-transform: uppercase;
        }

        .summary-row {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            flex: 1;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            background: #f8f9fa;
        }

        .stat-card strong {
            display: block;
            font-size: 16pt;
            color: #2a5298;
            margin-bottom: 5px;
        }

        .stat-card span {
            font-size: 8pt;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background: #2a5298;
            color: white;
            text-align: left;
            padding: 10px;
            font-size: 9pt;
            border: 1px solid #2a5298;
        }

        td {
            padding: 8px;
            border: 1px solid #dee2e6;
            vertical-align: top;
            font-size: 8.5pt;
        }

        tr:nth-child(even) {
            background: #fcfcfc;
        }

        .footer {
            position: fixed;
            bottom: 10mm;
            left: 15mm;
            right: 15mm;
            text-align: center;
            font-size: 8pt;
            color: #999;
            border-top: 1px solid #eee;
            padding-top: 5px;
        }

        .filter-info {
            margin-bottom: 15px;
            font-size: 9pt;
            background: #eef2f7;
            padding: 10px;
            border-radius: 5px;
            border-left: 4px solid #2a5298;
        }

        @media print {
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body onload="window.print()">
    <div class="no-print" style="position:fixed; top:20px; right:20px;">
        <button onclick="window.print()" style="padding:10px 20px; background:#2a5298; color:white; border:none; border-radius:5px; cursor:pointer; font-weight:bold;">🖨️ Print to PDF</button>
    </div>
    <div class="header">
        <?php if ($logo_src): ?><img src="<?= $logo_src ?>" class="logo"><?php endif; ?>
        <div class="report-title">
            <h1>Security Audit Log</h1>
            <div class="small">Generated on <?= date('F j, Y, g:i A') ?> by <?= htmlspecialchars($_SESSION['username']) ?></div>
        </div>
    </div>
    <div class="summary-row">
        <div class="stat-card"><strong><?= number_format($statsToday) ?></strong><span>Events Today</span></div>
        <div class="stat-card"><strong><?= number_format($statsMonth) ?></strong><span>Monthly Velocity</span></div>
        <div class="stat-card"><strong><?= htmlspecialchars($topContributor['name']) ?></strong><span>Top User (<?= $topContributor['count'] ?>)</span></div>
    </div>
    <div class="filter-info">
        <strong>Report Parameters:</strong> Category: <?= $filter ?> | Period: <?= $dateFrom ?: 'Beginning' ?> to <?= $dateTo ?: 'Now' ?> | Search: <?= $search ?: 'None' ?>
    </div>
    <table>
        <thead>
            <tr>
                <th width="18%">Timestamp</th>
                <th width="15%">User</th>
                <th width="20%">Action</th>
                <th>Details / Target</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></td>
                    <td><?= htmlspecialchars($log['username'] ?? 'System') ?></td>
                    <td><?= htmlspecialchars(str_replace('_', ' ', $log['action'])) ?></td>
                    <td><?= htmlspecialchars($log['details']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="footer">Confidential Internal Audit Record - TES Philippines HR 201 System - Generated by Authorized Personnel Only</div>
</body>

</html>