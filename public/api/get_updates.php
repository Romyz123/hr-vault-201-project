<?php
// Adjust path to config based on folder structure
// If this is in public/api/, we go back two levels to config
require '../../config/db.php'; 

header('Content-Type: application/json');

// 1. FETCH NOTIFICATIONS
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
$count = count($notifications);

// 2. GENERATE HTML FOR DROPDOWN
ob_start();
if ($count > 0) {
    echo '<li><h6 class="dropdown-header bg-light border-bottom fw-bold">Compliance Alerts</h6></li>';
    foreach ($notifications as $notif) {
        $days = ceil((strtotime($notif['expiry_date']) - time()) / (60 * 60 * 24));
        $color = ($days < 0) ? 'text-danger' : 'text-warning';
        $msg = ($days < 0) ? "EXPIRED" : "Expiring in $days days";
        $icon = ($days < 0) ? 'bi-exclamation-octagon-fill' : 'bi-exclamation-triangle-fill';
        
        // Exact HTML matching your dashboard
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
                <button class="btn btn-sm btn-outline-success ms-2" 
                        onclick="openResolveModal(\''.$notif['id'].'\', \''.htmlspecialchars($notif['original_name']).'\')"
                        title="Report Action / Resolve">
                    <i class="bi bi-check2-circle"></i>
                </button>
            </div>
        </li>';
    }
} else {
    echo '<li class="p-4 text-center text-muted small">
            <i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>
            All documents are up to date!
          </li>';
}
$html = ob_get_clean();

// 3. FETCH CHART DATA
$statsQuery = $pdo->query("SELECT category, COUNT(*) as cnt FROM documents GROUP BY category");
$stats = $statsQuery->fetchAll(PDO::FETCH_KEY_PAIR);

echo json_encode([
    'count' => $count,
    'html'  => $html,
    'chartLabels' => array_keys($stats),
    'chartValues' => array_values($stats)
]);
?>