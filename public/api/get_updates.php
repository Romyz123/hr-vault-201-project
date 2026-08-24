<?php
// [FILE] public/api/get_updates.php
// [STATUS] MERGED: Notifications + Chart + Live Dashboard Stats

require '../../config/db.php';
session_start();
header('Content-Type: application/json');

// [SECURITY] This endpoint contains HR metadata and is unavailable to guests.
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

try {
    $userId = (int) $_SESSION['user_id'];
    $userRole = $_SESSION['role'] ?? '';
    $isHrRole = in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true);

    $hideZeros = $_SESSION['hide_chart_zeros'] ?? false;

    // [NEW] Fetch Dynamic Categories for consistency
    $dynamicCats = [];
    try {
        $stmt = $pdo->query("SELECT DISTINCT name FROM document_requirements ORDER BY name ASC");
        $dynamicCats = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $dynamicCats = array_filter($dynamicCats, function ($cat) {
            return strcasecmp(trim($cat), 'Others') !== 0;
        });
    } catch (Exception $e) {
        $dynamicCats = ['201 Files', 'Contract', 'Government IDs', 'Medical', 'Memo / DA', 'Evaluation', 'Certificate', 'Training Record'];
    }

    // [FIX] Fallback if table is empty but exists
    if (empty($dynamicCats)) {
        $dynamicCats = ['201 Files', 'Contract', 'Government IDs', 'Medical', 'Memo / DA', 'Evaluation', 'Certificate', 'Training Record'];
    }

    // Check for column existence
    $hasDeletedAt = false;
    $hasUploadedBy = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM documents LIKE 'deleted_at'");
        if ($chk->rowCount() > 0) $hasDeletedAt = true;
        $chk2 = $pdo->query("SHOW COLUMNS FROM documents LIKE 'uploaded_by'");
        if ($chk2->rowCount() > 0) $hasUploadedBy = true;
    } catch (Exception $e) {
    }

    // ============================================================
    // PART 0: DB MESSAGES (Clearable) - Needed for "Clear Read" button
    // ============================================================
    $msgStmt = $pdo->prepare("SELECT id, title, message, type, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
    $msgStmt->execute([$userId]);
    $db_notifs = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
    $msgCount = count($db_notifs);

    // ============================================================
    // PART 1: COMPLIANCE ALERTS (Your Original Code)
    // ============================================================
    $alertDate = date('Y-m-d', strtotime('+30 days'));

    $docQuery = "
        SELECT d.id, d.category, d.original_name, d.expiry_date, e.first_name, e.last_name, e.emp_id 
        FROM documents d 
        JOIN employees e ON d.employee_id = e.emp_id 
        WHERE d.expiry_date IS NOT NULL 
        AND d.expiry_date <= ? 
        AND d.is_resolved = 0 
    ";
    if ($hasDeletedAt) {
        $docQuery .= " AND d.deleted_at IS NULL";
    }
    $notificationParams = [$alertDate];
    if ($hasUploadedBy && !$isHrRole) {
        $docQuery .= " AND d.uploaded_by = ?";
        $notificationParams[] = $userId;
    } elseif (!$isHrRole) {
        // Do not expose document metadata when ownership cannot be verified.
        $docQuery .= " AND 1 = 0";
    }
    $docQuery .= " ORDER BY d.expiry_date ASC";
    $stmt = $pdo->prepare($docQuery);
    $stmt->execute($notificationParams);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $alertCount = count($notifications);

    // [NEW] Check Pending Requests (For Admin/HR)
    $pendingHtml = '';
    $userRole = $_SESSION['role'] ?? '';
    $pCount = 0;
    if ($isHrRole) {
        // [FIX] Only count PENDING requests so approved ones disappear
        $pCount = $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'PENDING'")->fetchColumn();
        if ($pCount > 0) {
            $pendingHtml = '
            <li class="border-bottom py-2 px-3 bg-body-tertiary">
                <a href="admin_approval.php" class="text-decoration-none text-body d-block">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-clipboard-data-fill text-primary fs-5 me-2"></i>
                        <div style="line-height: 1.2;">
                            <small class="fw-bold d-block">Approval Center</small>
                            <span class="extra-small fw-bold text-primary">' . $pCount . ' Request(s) Pending</span>
                        </div>
                    </div>
                </a>
            </li>';
        }
    }

    // Total Count for Badge
    $totalBadgeCount = $msgCount + $alertCount + ($pCount > 0 ? 1 : 0);

    // Generate HTML for Dropdown
    ob_start();
    echo '<li><div class="dropdown-header bg-body-tertiary border-bottom d-flex justify-content-between align-items-center">
            <span class="fw-bold">Notifications</span>';
    if ($msgCount > 0) {
        echo '<form method="POST" class="m-0" action="index.php"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '') . '"><button name="clear_notifs" class="btn btn-link btn-sm text-decoration-none p-0" style="font-size: 0.8rem;">Clear Read</button></form>';
    }
    echo '</div></li>';

    if ($totalBadgeCount > 0) {
        echo $pendingHtml; // Show pending requests at the top
        foreach ($notifications as $notif) {
            $days = ceil((strtotime($notif['expiry_date']) - time()) / (60 * 60 * 24));
            $color = ($days < 0) ? 'text-danger' : 'text-warning';
            $msg = ($days < 0) ? "EXPIRED" : "Expiring in $days days";
            $icon = ($days < 0) ? 'bi-exclamation-octagon-fill' : 'bi-exclamation-triangle-fill';

            echo '
            <li class="border-bottom py-2 px-3">
                <div class="d-flex justify-content-between align-items-center">
                    <a href="index.php?search=' . htmlspecialchars($notif['emp_id']) . '&resolve_doc=' . $notif['id'] . '&doc_name=' . urlencode($notif['original_name']) . '" class="text-decoration-none text-body w-100">
                        <div class="d-flex align-items-center">
                            <i class="bi ' . $icon . ' ' . $color . ' fs-5 me-2"></i>
                            <div style="line-height: 1.2;">
                                <small class="fw-bold d-block">' . htmlspecialchars($notif['first_name'] . ' ' . $notif['last_name']) . '</small>
                                <span class="text-muted" style="font-size: 0.75rem;">
                                    ' . htmlspecialchars($notif['category']) . ': <em class="text-body">' . htmlspecialchars($notif['original_name']) . '</em>
                                </span>
                                <br><span class="extra-small fw-bold ' . $color . '">' . $msg . '</span>
                            </div>
                        </div>
                    </a>
                </div>
            </li>';
        }
        // Render DB Messages (The clearable ones)
        foreach ($db_notifs as $n) {
            $icon = ($n['type'] === 'success') ? "bi-check-circle-fill text-success" : (($n['type'] === 'danger') ? "bi-x-circle-fill text-danger" : "bi-info-circle-fill text-info");
            echo '<li><div class="dropdown-item white-space-normal">
                    <div class="d-flex align-items-start">
                        <i class="bi ' . $icon . ' fs-4 me-2"></i>
                        <div class="w-100">
                            <h6 class="mb-0 small fw-bold">' . htmlspecialchars($n['title']) . '</h6>
                            <p class="mb-1 small text-muted" style="font-size: 0.85rem;">' . htmlspecialchars($n['message']) . '</p>
                            <small class="text-secondary" style="font-size: 0.7rem;">' . date('M d, h:i A', strtotime($n['created_at'])) . '</small>
                        </div>
                    </div>
                  </div></li>';
        }
    } else {
        echo '<li class="p-4 text-center text-muted small"><i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>All documents are up to date!</li>';
    }
    $html = ob_get_clean();

    // ============================================================
    // PART 2: LIVE DASHBOARD STATS (The New Feature)
    // ============================================================

    // A. Active Headcount
    $activeHeadcount = 0;
    $pendingCases = 0;
    if ($isHrRole) {
        $headStmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'Active'");
        $activeHeadcount = $headStmt->fetchColumn();

        // B. Pending Disciplinary Cases
        $caseStmt = $pdo->query("SELECT COUNT(*) FROM disciplinary_cases WHERE status = 'Open'");
        $pendingCases = $caseStmt->fetchColumn();
    }

    // ============================================================
    // PART 3: OUTPUT EVERYTHING
    // ============================================================


    // [FIX] Exclude deleted files from the count
    // [SYNC] Fetch dynamic requirements from database to match Tracker and Dashboard logic
    $reqStmt = $pdo->query("SELECT name, keywords FROM document_requirements ORDER BY id ASC");
    $reqList = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
    $REQUIRED_DOCS = [];
    foreach ($reqList as $r) {
        $REQUIRED_DOCS[$r['name']] = array_map('trim', explode(',', $r['keywords']));
    }
    if (empty($REQUIRED_DOCS)) {
        $REQUIRED_DOCS = [
            '201 Files' => ['201', 'PDS', 'Data Sheet', 'Resume'],
            'Valid ID'  => ['ID', 'Passport', 'License', 'SSS', 'PhilHealth'],
            'Contract'  => ['Contract', 'Appointment', 'Offer'],
            'Medical'   => ['Medical', 'Fit to Work', 'Exam'],
            'Clearance' => ['NBI', 'Police', 'Barangay']
        ];
    }

    // Fetch all active documents for active employees (matching index.php logic)
    $hasEmployeeDeletedAt = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM employees LIKE 'deleted_at'");
        if ($chk->rowCount() > 0) $hasEmployeeDeletedAt = true;
    } catch (Exception $e) {
    }

    $docsForStatsSql = "SELECT d.category, d.original_name FROM documents d 
                        INNER JOIN employees e ON d.employee_id = e.emp_id
                        WHERE 1=1";
    if ($hasEmployeeDeletedAt) $docsForStatsSql .= " AND e.deleted_at IS NULL";
    if ($hasDeletedAt) $docsForStatsSql .= " AND d.deleted_at IS NULL";
    if ($hasUploadedBy && !$isHrRole) {
        $docsForStatsSql .= " AND d.uploaded_by = " . (int)$userId;
    } elseif (!$isHrRole) {
        $docsForStatsSql .= " AND 1 = 0";
    }
    $docsForStats = $pdo->query($docsForStatsSql)->fetchAll(PDO::FETCH_ASSOC);
    $chartStats = array_fill_keys(array_keys($REQUIRED_DOCS), 0);
    $unCatCount = 0;

    foreach ($docsForStats as $doc) {
        $matched = false;
        $cat = trim($doc['category'] ?? '');
        $name = $doc['original_name'];
        foreach ($REQUIRED_DOCS as $reqName => $keywords) {
            if (strcasecmp($cat, $reqName) === 0) {
                $matched = true;
            } else {
                foreach ($keywords as $k) {
                    if ($k !== '' && (stripos($name, $k) !== false || stripos($cat, $k) !== false)) {
                        $matched = true;
                        break;
                    }
                }
            }
            if ($matched) {
                $chartStats[$reqName]++;
                break;
            }
        }
        if (!$matched) $unCatCount++;
    }

    if ($unCatCount > 0) $chartStats['Uncategorized'] = $unCatCount;

    // Remove zeros from chart if setting is active
    if ($hideZeros) {
        $chartStats = array_filter($chartStats, fn($v) => $v > 0);
    }

    echo json_encode([
        'status' => 'success',
        // Notification Data
        'count' => $totalBadgeCount,
        'msgCount' => $msgCount,
        'html'  => $html,
        // Live Dashboard Data
        'headcount' => number_format((int)$activeHeadcount),
        'cases' => number_format((int)$pendingCases),
        // Chart Data
        'chartLabels' => array_keys($chartStats),
        'chartValues' => array_values($chartStats)
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
