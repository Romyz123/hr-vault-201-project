<?php
// public/evaluation_report.php
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Filter Year
$yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Fetch Average Scores by Department
$sql = "SELECT e.dept, COUNT(pe.id) as eval_count, AVG(pe.score) as avg_score 
        FROM performance_evaluations pe 
        JOIN employees e ON pe.employee_id = e.id 
        WHERE YEAR(pe.eval_date) = ?
        GROUP BY e.dept 
        ORDER BY avg_score DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$yearFilter]);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare Data for Chart
$labels = [];
$scores = [];
foreach ($data as $row) {
    $labels[] = $row['dept'];
    $scores[] = round($row['avg_score'], 2);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Evaluation Report</title>
    <link rel="icon" href="assets/tesp-logo-1.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/chart.min.js"></script>
    <style>
        /* =========================================
           PRINT STYLES: Custom layout for paper
           ========================================= */
        @media print {
            @page {
                size: portrait;
                /* Portrait works best when stacking chart and table */
                margin: 0.5in;
            }

            /* Hide Navbar and Filter areas */
            nav,
            .no-print {
                display: none !important;
            }

            body {
                background: white !important;
            }

            .container {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            /* Stack columns vertically so the chart has room to breathe */
            .col-md-8,
            .col-md-4 {
                width: 100% !important;
                flex: 0 0 100% !important;
                max-width: 100% !important;
                margin-bottom: 20px !important;
            }

            /* Clean up cards to look like standard documents */
            .card {
                border: none !important;
                box-shadow: none !important;
            }

            .card-header {
                background-color: transparent !important;
                color: black !important;
                border-bottom: 2px solid #000 !important;
                padding-left: 0 !important;
                font-size: 14pt !important;
            }

            /* Ensure table lines print clearly */
            table {
                border-collapse: collapse !important;
                width: 100% !important;
            }

            th,
            td {
                border: 1px solid #dee2e6 !important;
            }

            /* Dynamic Print Title pulling from the body data attribute */
            body::before {
                content: "Department Evaluation Report (" attr(data-year) ")";
                display: block;
                text-align: center;
                font-size: 18pt;
                font-weight: bold;
                margin-bottom: 30px;
                color: black;
            }

            /* Force background colors (like chart bars and table striping) to print */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>

<body class="bg-light" data-year="<?php echo $yearFilter; ?>">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <span class="navbar-text text-white">Department Evaluation Report</span>
        </div>
    </nav>

    <div class="container">
        <div class="card shadow-sm mb-4 no-print">
            <div class="card-body py-2">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-auto fw-bold">Filter Year:</div>
                    <div class="col-auto">
                        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php
                            $currentYear = date('Y');
                            for ($y = $currentYear; $y >= 2020; $y--) {
                                $selected = ($y == $yearFilter) ? 'selected' : '';
                                echo "<option value='$y' $selected>$y</option>";
                            }
                            ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-bold">Average Score by Department</div>
                    <div class="card-body">
                        <canvas id="deptChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-primary text-white fw-bold">Summary Table</div>
                    <div class="card-body p-0">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Dept</th>
                                    <th class="text-center">Count</th>
                                    <th class="text-end">Avg Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data as $row):
                                    $avg = round($row['avg_score'], 2);
                                    $color = $avg >= 85 ? 'text-success' : ($avg >= 75 ? 'text-primary' : 'text-danger');
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['dept']); ?></td>
                                        <td class="text-center"><?php echo $row['eval_count']; ?></td>
                                        <td class="text-end fw-bold <?php echo $color; ?>"><?php echo $avg; ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center no-print">
            <button onclick="window.print()" class="btn btn-dark"><i class="bi bi-printer"></i> Print Report</button>
        </div>
    </div>

    <script>
        const ctx = document.getElementById('deptChart');
        const deptChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Average Score',
                    data: <?php echo json_encode($scores); ?>,
                    backgroundColor: '#0d6efd',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100
                    }
                },
                // Add basic animation config so it doesn't break while printing
                animation: {
                    duration: 0
                }
            }
        });

        // [NEW] Dark Mode Adapter for Chart
        function updateChartTheme() {
            const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            const textColor = isDark ? '#adb5bd' : '#6c757d';
            const gridColor = isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.05)';

            if (deptChart.options.scales.x) {
                deptChart.options.scales.x.ticks = deptChart.options.scales.x.ticks || {};
                deptChart.options.scales.x.ticks.color = textColor;
                deptChart.options.scales.x.grid = deptChart.options.scales.x.grid || {};
                deptChart.options.scales.x.grid.color = gridColor;
            }
            if (deptChart.options.scales.y) {
                deptChart.options.scales.y.ticks = deptChart.options.scales.y.ticks || {};
                deptChart.options.scales.y.ticks.color = textColor;
                deptChart.options.scales.y.grid = deptChart.options.scales.y.grid || {};
                deptChart.options.scales.y.grid.color = gridColor;
            }
            deptChart.update();
        }

        new MutationObserver(updateChartTheme).observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-bs-theme']
        });
        updateChartTheme(); // Initial check
    </script>
</body>

</html>