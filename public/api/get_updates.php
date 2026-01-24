<?php
// [FILE] public/api/get_updates.php
// [STATUS] MERGED: Notifications + Chart + Live Dashboard Stats

require '../../config/db.php'; 
header('Content-Type: application/json');

try {
    // ============================================================
    // PART 1: COMPLIANCE ALERTS (Your Original Code)
    // ============================================================
    $alertDate = date('Y-m-d', strtotime('+30 days'));
    $stmt = $pdo->prepare("
        SELECT d.id, d.category, d.original_name, d.expiry_date, e.first_name, e.last_name, e.emp_id 
        FROM documents d 
        JOIN employees e ON d.employee_id = e.emp_id 
        WHERE d.expiry_date IS NOT NULL 
        AND d.expiry_date <= ? 
        AND d.is_resolved = 0 
        ORDER BY d.expiry_date ASC
    ");
    $stmt->execute([$alertDate]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $notifCount = count($notifications);

    // Generate HTML for Dropdown
    ob_start();
    if ($notifCount > 0) {
        echo '<li><h6 class="dropdown-header bg-light border-bottom fw-bold">Compliance Alerts</h6></li>';
        foreach ($notifications as $notif) {
            $days = ceil((strtotime($notif['expiry_date']) - time()) / (60 * 60 * 24));
            $color = ($days < 0) ? 'text-danger' : 'text-warning';
            $msg = ($days < 0) ? "EXPIRED" : "Expiring in $days days";
            $icon = ($days < 0) ? 'bi-exclamation-octagon-fill' : 'bi-exclamation-triangle-fill';
            
            echo '
            <li class="border-bottom py-2 px-3">
                <div class="d-flex justify-content-between align-items-center">
                    <a href="index.php?search='.htmlspecialchars($notif['emp_id']).'" class="text-decoration-none text-dark w-100">
                        <div class="d-flex align-items-center">
                            <i class="bi '.$icon.' '.$color.' fs-5 me-2"></i>
                            <div style="line-height: 1.2;">
                                <small class="fw-bold d-block">'.htmlspecialchars($notif['first_name'] . ' ' . $notif['last_name']).'</small>
                                <span class="text-muted" style="font-size: 0.75rem;">
                                    '.htmlspecialchars($notif['category']).': <em class="text-dark">'.htmlspecialchars($notif['original_name']).'</em>
                                </span>
                                <br><span class="extra-small fw-bold '.$color.'">'.$msg.'</span>
                            </div>
                        </div>
                    </a>
                </div>
            </li>';
        }
    } else {
        echo '<li class="p-4 text-center text-muted small"><i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>All documents are up to date!</li>';
    }
    $html = ob_get_clean();

    // ============================================================
    // PART 2: LIVE DASHBOARD STATS (The New Feature)
    // ============================================================
    
    // A. Active Headcount
    $headStmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'Active'");
    $activeHeadcount = $headStmt->fetchColumn();

    // B. Pending Disciplinary Cases
    $caseStmt = $pdo->query("SELECT COUNT(*) FROM disciplinary_cases WHERE status = 'Open'");
    $pendingCases = $caseStmt->fetchColumn();

    // ============================================================
    // PART 3: OUTPUT EVERYTHING
    // ============================================================
    
    // Your existing chart logic
    $statsQuery = $pdo->query("
        SELECT COALESCE(NULLIF(TRIM(category), ''), 'Documents for Employee'), COUNT(*) 
        FROM documents 
        GROUP BY 1
    ");
    $stats = $statsQuery->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode([
        'status' => 'success',
        // Notification Data
        'count' => $notifCount,
        'html'  => $html,
        // Live Dashboard Data
        'headcount' => $activeHeadcount,
        'cases' => $pendingCases,
        // Chart Data
        'chartLabels' => array_keys($stats),
        'chartValues' => array_values($stats)
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>