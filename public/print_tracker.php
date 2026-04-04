<?php
// public/print_tracker.php
require '../config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

// 1. FETCH REQUIREMENTS
$REQUIRED_DOCS = [];
try {
    $stmt = $pdo->query("SELECT * FROM document_requirements ORDER BY id ASC");
    $reqList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reqList as $r) {
        $REQUIRED_DOCS[$r['name']] = array_map('trim', explode(',', $r['keywords']));
    }
} catch (Exception $e) {
    // Fallback
    $REQUIRED_DOCS = ['201 Files' => ['201'], 'Valid ID' => ['ID'], 'Contract' => ['Contract']];
}

// 2. GET FILTERS
$dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$compliance = isset($_GET['compliance']) ? trim($_GET['compliance']) : '';
$search = preg_replace('/[^a-zA-Z0-9\-_ ,]/', '', $search);

// 3. FETCH EMPLOYEES
$sql = "SELECT emp_id, first_name, last_name, dept, job_title, status FROM employees WHERE 1=1";
$params = [];

if (!empty($dept)) {
    $sql .= " AND dept = ?";
    $params[] = $dept;
}
if (!empty($type)) {
    $sql .= " AND (employment_type = ? OR agency_name = ?)";
    $params[] = $type;
    $params[] = $type;
}
if (!empty($search)) {
    $terms = preg_split('/[\s,]+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($terms as $term) {
        $sql .= " AND (emp_id LIKE ? OR last_name LIKE ? OR first_name LIKE ?)";
        $t = "%$term%";
        $params[] = $t;
        $params[] = $t;
        $params[] = $t;
    }
}
$sql .= " ORDER BY last_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. FETCH DOCUMENTS & EXEMPTIONS
$docSql = "SELECT employee_id, category, original_name FROM documents";
try {
    $chk = $pdo->query("SHOW COLUMNS FROM documents LIKE 'deleted_at'");
    if ($chk->rowCount() > 0) $docSql .= " WHERE deleted_at IS NULL";
} catch (Exception $e) {
}
$allDocs = $pdo->query($docSql)->fetchAll(PDO::FETCH_ASSOC);

$docsMap = [];
foreach ($allDocs as $d) {
    $empId = $d['employee_id'];
    foreach ($REQUIRED_DOCS as $reqKey => $keywords) {
        if (stripos($d['category'], $reqKey) !== false) {
            $docsMap[$empId][$reqKey] = true;
        } else {
            foreach ($keywords as $k) {
                if (stripos($d['original_name'], $k) !== false || stripos($d['category'], $k) !== false) {
                    $docsMap[$empId][$reqKey] = true;
                    break;
                }
            }
        }
    }
}

$exemptMap = [];
try {
    $exStmt = $pdo->query("SELECT employee_id, requirement_name FROM document_exemptions");
    while ($row = $exStmt->fetch(PDO::FETCH_ASSOC)) {
        $exemptMap[$row['employee_id']][$row['requirement_name']] = true;
    }
} catch (Exception $e) {
}

// 5. FILTER COMPLIANCE
if ($compliance !== '') {
    $filtered = [];
    foreach ($employees as $emp) {
        $id = $emp['emp_id'];
        $have = 0;
        $totalReq = count($REQUIRED_DOCS);
        foreach ($REQUIRED_DOCS as $reqKey => $keywords) {
            if (isset($docsMap[$id][$reqKey]) || isset($exemptMap[$id][$reqKey])) $have++;
        }
        $percent = ($totalReq > 0) ? ($have / $totalReq) * 100 : 0;

        if ($compliance === 'complete' && $percent >= 100) $filtered[] = $emp;
        elseif ($compliance === 'incomplete' && $percent < 100) $filtered[] = $emp;
        elseif ($compliance === 'in_progress' && $percent > 0 && $percent < 100) $filtered[] = $emp;
        elseif ($compliance === 'empty' && $percent == 0) $filtered[] = $emp;
    }
    $employees = $filtered;
}

$logo_paths = [
    __DIR__ . '/assets/images/tesp-logo-1.png',
    __DIR__ . '/uploads/tesp-logo.png',
    __DIR__ . '/uploads/tesp logo 1.png',
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
    <title>Compliance Report</title>
    <link rel="icon" href="assets/tesp-logo.png?v=4" type="image/png">
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th,
        td {
            border: 1px solid #333;
            padding: 5px;
            text-align: center;
        }

        th {
            background-color: #eee;
        }

        .text-left {
            text-align: left;
        }

        .status-ok {
            color: green;
            font-weight: bold;
        }

        .status-missing {
            color: red;
            font-weight: bold;
        }

        .status-na {
            color: gray;
            font-style: italic;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        @media print {
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body>
    <div class="no-print" style="margin-bottom: 10px;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; font-weight: bold;">🖨️ Print Report</button>
        <button onclick="window.close()" style="padding: 10px 20px; cursor: pointer;">Close</button>
    </div>

    <div class="header">
        <?php if (!empty($logo_src)): ?>
            <img src="<?php echo htmlspecialchars($logo_src); ?>" alt="Company Logo" style="height: 60px; display: block; margin: 0 auto 10px auto;">
        <?php endif; ?> <h2>Document Compliance Report</h2>
        <p>
            Date: <?php echo date('F d, Y'); ?><br>
            Filter: <?php echo $compliance ? ucfirst(str_replace('_', ' ', $compliance)) : 'All'; ?> |
            Agency: <?php echo $type ? htmlspecialchars($type) : 'All'; ?> |
            Dept: <?php echo $dept ? htmlspecialchars($dept) : 'All'; ?>
        </p>
    </div>

    <table>
        <thead>
            <tr>
                <th class="text-left">Employee</th>
                <th>Dept</th>
                <th>Progress</th>
                <?php foreach ($REQUIRED_DOCS as $cat => $k): ?>
                    <th><?php echo htmlspecialchars($cat); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($employees as $emp):
                $id = $emp['emp_id'];
                $have = 0;
                $totalReq = count($REQUIRED_DOCS);
                $rowCells = [];
                foreach ($REQUIRED_DOCS as $reqKey => $keywords) {
                    if (isset($docsMap[$id][$reqKey])) {
                        $rowCells[$reqKey] = '<span class="status-ok">✔</span>';
                        $have++;
                    } elseif (isset($exemptMap[$id][$reqKey])) {
                        $rowCells[$reqKey] = '<span class="status-na">N/A</span>';
                        $have++;
                    } else {
                        $rowCells[$reqKey] = '<span class="status-missing">X</span>';
                    }
                }
                $percent = ($totalReq > 0) ? round(($have / $totalReq) * 100) : 0;
            ?>
                <tr>
                    <td class="text-left">
                        <strong><?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']); ?></strong><br>
                        <small><?php echo htmlspecialchars($emp['emp_id']); ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($emp['dept']); ?></td>
                    <td><?php echo htmlspecialchars($percent); ?>%</td>
                    <?php foreach ($rowCells as $cell): ?>
                        <td><?php echo $cell; ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>

</html>