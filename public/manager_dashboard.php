<?php
// ======================================================
// [FILE] public/manager_dashboard.php
// [PURPOSE] Specialized Dashboard for Managers
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();

// 1. SECURITY: Only ADMIN and MANAGER
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
    header("Location: index.php");
    exit;
}

// 2. FETCH KEY METRICS

// A. Pending Approvals
$reqStmt = $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'PENDING'");
$pendingCount = $reqStmt->fetchColumn();

// B. Expiring Documents (Next 30 Days)
$expDate = date('Y-m-d', strtotime('+30 days'));
$expStmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE expiry_date <= ? AND is_resolved = 0 AND deleted_at IS NULL");
$expStmt->execute([$expDate]);
$expiringCount = $expStmt->fetchColumn();

// C. Headcount Stats
$activeCount = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'Active'")->fetchColumn();
$probationCount = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'Active' AND employment_type = 'TESP Direct' AND DATEDIFF(NOW(), hire_date) < 180")->fetchColumn();

// D. Recent Activities (Last 5)
$logStmt = $pdo->query("SELECT a.*, u.username FROM activity_logs a JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 5");
$logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

// E. Recent Uploads (Last 5) - [WIDGET]
$uploadStmt = $pdo->query("
    SELECT d.original_name, d.category, d.uploaded_at, e.first_name, e.last_name, d.file_uuid 
    FROM documents d 
    LEFT JOIN employees e ON d.employee_id = e.emp_id 
    WHERE d.deleted_at IS NULL
    ORDER BY d.uploaded_at DESC 
    LIMIT 5
");
$recentUploads = $uploadStmt->fetchAll(PDO::FETCH_ASSOC);

// F. Failed Logins (Last 24h) - [ADMIN ONLY]
$failedLogins = 0;
if (($_SESSION['role'] ?? '') === 'ADMIN') {
    $failStmt = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'LOGIN_FAILED' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $failedLogins = $failStmt->fetchColumn();
}

// G. Age Demographics (Active)
$ageBands = ['18-25' => 0, '26-35' => 0, '36-45' => 0, '46-55' => 0, '56+' => 0];
$ageStmt = $pdo->query("SELECT birth_date FROM employees WHERE status = 'Active' AND birth_date IS NOT NULL AND birth_date != '0000-00-00'");
$todayObj = new DateTime('today');
while ($row = $ageStmt->fetch(PDO::FETCH_ASSOC)) {
    $bDateObj = date_create($row['birth_date']);
    if ($bDateObj) {
        $age = date_diff($bDateObj, $todayObj)->y;
        if ($age <= 25) $ageBands['18-25']++;
        elseif ($age <= 35) $ageBands['26-35']++;
        elseif ($age <= 45) $ageBands['36-45']++;
        elseif ($age <= 55) $ageBands['46-55']++;
        else $ageBands['56+']++;
    }
}
$ageLabels = json_encode(array_keys($ageBands));
$ageCounts = json_encode(array_values($ageBands));

?>
<?php include 'header.php'; ?>

<div class="container">

    <!-- WELCOME BANNER -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm d-flex justify-content-between align-items-center p-3">
                <div>
                    <h4 class="mb-1 text-primary">Welcome back, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h4>
                    <p class="text-muted mb-0">Here is what requires your attention today.</p>
                </div>
                <div class="text-end">
                    <h2 class="fw-bold mb-0"><?php echo date('j'); ?></h2>
                    <span class="text-uppercase small text-muted"><?php echo date('F Y'); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ACTION CARDS -->
    <div class="row g-4 mb-4">
        <!-- Approvals -->
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-start border-4 border-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="text-muted text-uppercase mb-0">Pending Requests</h6>
                        <div class="icon-shape bg-warning text-white rounded-circle p-2">
                            <i class="bi bi-inbox-fill"></i>
                        </div>
                    </div>
                    <h2 class="fw-bold mb-3"><?php echo $pendingCount; ?></h2>
                    <a href="admin_approval.php" class="btn btn-sm btn-outline-warning w-100">Review Requests</a>
                </div>
            </div>
        </div>

        <!-- Expirations -->
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-start border-4 border-danger">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="text-muted text-uppercase mb-0">Expiring Docs</h6>
                        <div class="icon-shape bg-danger text-white rounded-circle p-2">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        </div>
                    </div>
                    <h2 class="fw-bold mb-3"><?php echo $expiringCount; ?></h2>
                    <a href="expiry_report.php" class="btn btn-sm btn-outline-danger w-100">View Forecast</a>
                </div>
            </div>
        </div>

        <!-- Headcount -->
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-start border-4 border-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="text-muted text-uppercase mb-0">Active Workforce</h6>
                        <div class="icon-shape bg-success text-white rounded-circle p-2">
                            <i class="bi bi-people-fill"></i>
                        </div>
                    </div>
                    <h2 class="fw-bold mb-0"><?php echo $activeCount; ?></h2>
                    <small class="text-muted"><?php echo $probationCount; ?> Probationary</small>
                    <a href="analytics.php" class="btn btn-sm btn-outline-success w-100 mt-3">View Analytics</a>
                </div>
            </div>
        </div>
    </div>

    <!-- SYSTEM HEALTH WIDGET (ADMIN ONLY) -->
    <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-sm border-start border-4 border-danger">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-muted text-uppercase mb-1">System Health Alert</h6>
                            <div class="d-flex align-items-center">
                                <h2 class="fw-bold text-danger mb-0 me-2"><?php echo $failedLogins; ?></h2>
                                <span class="text-muted">Failed Login Attempts (Last 24h)</span>
                            </div>
                        </div>
                        <a href="security_audit.php" class="btn btn-sm btn-outline-danger"><i class="bi bi-shield-check"></i> View Security Audit</a>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- RECENT UPLOADS & DEMOGRAPHICS WIDGETS -->
    <div class="row mb-4">
        <div class="col-lg-8 mb-4 mb-lg-0">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-bold">
                    <i class="bi bi-cloud-arrow-up"></i> Recent Uploads
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>File</th>
                                <th>Employee</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentUploads)): ?>
                                <tr>
                                    <td colspan="3" class="text-center p-3 text-muted">No recent uploads.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentUploads as $up): ?>
                                    <tr>
                                        <td>
                                            <a href="view_doc.php?id=<?php echo htmlspecialchars($up['file_uuid'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="text-decoration-none fw-bold text-dark">
                                                <i class="bi bi-file-earmark-text text-secondary"></i> <?php echo htmlspecialchars($up['original_name']); ?>
                                            </a> <br><small class="text-muted"><?php echo htmlspecialchars($up['category']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($up['first_name'] . ' ' . $up['last_name']); ?></td>
                                        <td class="small text-muted"><?php echo date('M d', strtotime($up['uploaded_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- AGE DEMOGRAPHICS WIDGET -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header d-flex align-items-center justify-content-between fw-bold text-dark">
                    <div>
                        <i class="bi bi-pie-chart-fill text-warning"></i> Age Demographics
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary fw-bold" onclick="downloadSpecificChart('ageChart', 'Workforce_Age_Demographics')" title="Download Image"><i class="bi bi-image"></i></button>
                        <button class="btn btn-sm btn-outline-primary fw-bold" onclick="downloadAgeData()" title="Download Data as CSV"><i class="bi bi-download"></i> CSV</button>
                    </div>
                </div>
                <div class="card-body position-relative d-flex align-items-center justify-content-center" style="min-height: 250px;">
                    <canvas id="ageChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- QUICK LINKS -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header fw-bold"><i class="bi bi-lightning-charge"></i> Management Tools</div>
                <div class="card-body d-flex gap-2 flex-wrap">
                    <a href="add_employee.php" class="btn btn-outline-success"><i class="bi bi-person-plus-fill me-2"></i> Add Employee</a>
                    <a href="import_employees.php" class="btn btn-outline-success"><i class="bi bi-file-spreadsheet me-2"></i> Bulk Import</a>
                    <a href="tracker.php" class="btn btn-outline-info"><i class="bi bi-kanban me-2"></i> Compliance Tracker</a>
                    <a href="bulk_update_roles.php" class="btn btn-outline-warning"><i class="bi bi-people-fill me-2"></i> Bulk Update Roles</a>
                    <a href="bulk_contract.php" class="btn btn-outline-primary"><i class="bi bi-printer-fill me-2"></i> Bulk Contract Print</a>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="assets/chart.min.js"></script>
<script src="assets/bootstrap.bundle.min.js"></script>
<script>
    /**
     * [NEW] Exports current Age Demographics chart data to a CSV file.
     */
    function downloadAgeData() {
        if (!window.ageChartInstance) return;
        const labels = window.ageChartInstance.data.labels;
        const values = window.ageChartInstance.data.datasets[0].data;
        let csv = "\uFEFFAge Bracket,Count\n"; // Added BOM for Excel UTF-8
        labels.forEach((label, i) => {
            const cleanLabel = label.includes(',') ? `"${label}"` : label;
            csv += `${cleanLabel},${values[i]}\n`;
        });
        const blob = new Blob([csv], {
            type: 'text/csv;charset=utf-8;'
        });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `Age_Demographics_Stats_${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // [NEW] Global Download Function
    window.downloadSpecificChart = function(canvasId, filename) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        try {
            const destinationCanvas = document.createElement("canvas");
            destinationCanvas.width = canvas.width;
            destinationCanvas.height = canvas.height;
            const destCtx = destinationCanvas.getContext('2d');
            destCtx.fillStyle = '#FFFFFF';
            destCtx.fillRect(0, 0, canvas.width, canvas.height);
            destCtx.drawImage(canvas, 0, 0);

            const link = document.createElement('a');
            link.style.display = 'none';
            link.download = filename + '_' + new Date().toISOString().split('T')[0] + '.png';
            link.href = destinationCanvas.toDataURL('image/png');

            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            if (window.Swal) {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Chart downloaded!',
                    showConfirmButton: false,
                    timer: 2000
                });
            }
        } catch (e) {
            console.error('Download failed:', e);
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        // [NEW] 100% Offline Custom DataLabels Plugin
        const offlineDataLabels = {
            id: 'offlineDataLabels',
            afterDatasetsDraw(chart, args, options) {
                const {
                    ctx
                } = chart;
                ctx.save();
                ctx.font = 'bold 12px Helvetica, Arial, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                chart.data.datasets.forEach((dataset, i) => {
                    const meta = chart.getDatasetMeta(i);
                    if (meta.hidden) return;

                    meta.data.forEach((element, index) => {
                        let dataVal = dataset.data[index];
                        if (dataVal === undefined || dataVal === null || Number(dataVal) === 0) return;

                        let text = dataVal.toString();
                        if (chart.config.type === 'pie' || chart.config.type === 'doughnut') {
                            let total = dataset.data.reduce((a, b) => Number(a) + Number(b), 0);
                            let percent = Math.round((dataVal / total) * 100);
                            if (percent < 5) return;
                            text = `${dataVal} (${percent}%)`;
                        }

                        if (typeof element.tooltipPosition !== 'function') return;
                        let pos = element.tooltipPosition();
                        let x = pos.x;
                        let y = pos.y;
                        if ((chart.config.type === 'bar' || meta.type === 'bar') && element.base !== undefined) {
                            y = (element.base + pos.y) / 2;
                        }
                        ctx.strokeStyle = 'rgba(0, 0, 0, 0.75)';
                        ctx.lineWidth = 3;
                        ctx.strokeText(text, x, y);
                        ctx.fillStyle = '#ffffff';
                        ctx.fillText(text, x, y);
                    });
                });
                ctx.restore();
            }
        };
        Chart.register(offlineDataLabels);

        const ctxAge = document.getElementById('ageChart');
        if (ctxAge) {
            window.ageChartInstance = new Chart(ctxAge, {
                type: 'doughnut',
                data: {
                    labels: <?php echo $ageLabels; ?>,
                    datasets: [{
                        data: <?php echo $ageCounts; ?>,
                        backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#fd7e14', '#dc3545'],
                        borderWidth: 2,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15
                            }
                        }
                    },
                    cutout: '65%'
                }
            });

            // Dark Mode Adapter
            function updateChartTheme() {
                const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
                const textColor = isDark ? '#adb5bd' : '#6c757d';
                const borderColor = isDark ? '#212529' : '#fff';

                if (window.ageChartInstance) {
                    if (window.ageChartInstance.options.plugins.legend) {
                        window.ageChartInstance.options.plugins.legend.labels.color = textColor;
                    }
                    window.ageChartInstance.data.datasets[0].borderColor = borderColor;
                    window.ageChartInstance.update();
                }
            }
            new MutationObserver(updateChartTheme).observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['data-bs-theme']
            });
            updateChartTheme(); // Initial check
        }
    });
</script>
</body>

</html>