<?php
// --- START: UI REPAIR ---
// header.php

$doc_alerts = [];
$msgCount = 0;
$notifCount = 0;
$all_notifications = [];
$userRole = '';

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
}

if (!function_exists('h')) {
    function h(string $v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// [FIX] Ensure checkSessionTimeout is defined before calling it
if (!function_exists('checkSessionTimeout')) {
    require_once __DIR__ . '/../config/db.php';
}
require_once __DIR__ . '/options.php';

// [NEW] Auto-fetch settings if not provided by parent page
if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $userRole = ($userRole !== '') ? $userRole : strtoupper($_SESSION['role'] ?? '');

    // [FIX] Initialize variables to prevent 500 error on empty states
    $db_notifs = [];
    $doc_alerts = [];

    if (!isset($clientTimeout)) {
        try {
            $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
            // [SECURITY FIX] Always check fetchColumn() returns valid data before casting
            $result = $stmt ? $stmt->fetchColumn() : false;
            if ($result !== false && $result !== null && (int)$result > 0) {
                $clientTimeout = (int)$result;
            } else {
                $clientTimeout = 900; // Fallback to 15 minutes
            }
        } catch (Throwable $e) {
            $clientTimeout = 900;
        }
    }

    if (isset($_SESSION['user_id'])) {
        try {
            $notifStmt = $pdo->prepare("SELECT id, title, message, type, created_at, 'db_msg' as source, NULL as link_id FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
            $notifStmt->execute([$uid]);
            $db_notifs = $notifStmt->fetchAll() ?: [];
        } catch (Throwable $e) {
            $db_notifs = [];
        }
    }

    if (isset($_SESSION['user_id'])) {
        try {
            $alertDate = date('Y-m-d', strtotime('+30 days'));
            $docQuery = "SELECT d.id, d.original_name, d.expiry_date, e.emp_id AS real_emp_id FROM documents d JOIN employees e ON d.employee_id = e.emp_id WHERE d.is_resolved = 0 AND d.expiry_date IS NOT NULL AND d.expiry_date <= :alertDate";

            // Check for deleted_at column
            $chk = $pdo->query("SHOW COLUMNS FROM documents LIKE 'deleted_at'")->fetch();
            if ($chk) $docQuery .= " AND d.deleted_at IS NULL";

            // [SECURITY] Only filter by owner if not privileged AND the column exists
            $isRestrictedUpload = false;
            $chkUp = $pdo->query("SHOW COLUMNS FROM documents LIKE 'uploaded_by'")->fetch();
            if ($chkUp && !in_array($userRole, ['ADMIN', 'MANAGER', 'HR'])) {
                $docQuery .= " AND d.uploaded_by = :uid";
                $isRestrictedUpload = true;
            }

            $notifyStmt = $pdo->prepare($docQuery);
            $bindParams = [':alertDate' => $alertDate];
            if ($isRestrictedUpload) {
                $bindParams[':uid'] = $uid;
            }
            $notifyStmt->execute($bindParams);
            $raw_alerts = $notifyStmt->fetchAll() ?: [];

            foreach ($raw_alerts as $d) {
                $daysLeft = (int)floor((strtotime($d['expiry_date']) - time()) / 86400);
                $status = ($daysLeft < 0) ? 'EXPIRED' : ($daysLeft . ' days left');
                $doc_alerts[] = [
                    'id' => 'doc_' . $d['id'],
                    'title' => "Document Expiring: $status",
                    'message' => 'File: ' . $d['original_name'],
                    'type' => 'warning',
                    'created_at' => date('Y-m-d H:i:s'),
                    'source' => 'expiry',
                    'link_id' => $d['id'],
                    'doc_name' => $d['original_name'],
                    'emp_search' => $d['real_emp_id']
                ];
            }

            if (in_array($userRole, ['ADMIN', 'MANAGER', 'HR'])) {
                $pendCount = $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'PENDING'")->fetchColumn();
                if ($pendCount > 0) {
                    $doc_alerts[] = [
                        'id' => 'pending_reqs',
                        'title' => "Approval Center",
                        'message' => "$pendCount request(s) waiting for review.",
                        'type' => 'info',
                        'created_at' => date('Y-m-d H:i:s'),
                        'source' => 'request',
                        'link' => 'admin_approval.php'
                    ];
                }
            }
        } catch (Throwable $e) {
            $doc_alerts = [];
        }
    }

    $all_notifications = array_merge($db_notifs, $doc_alerts);
    usort($all_notifications, function ($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });
    $msgCount = count($db_notifs);
    $notifCount = $msgCount + count($doc_alerts);
}

$csrf_token = $_SESSION['csrf_token'] ?? '';

// Determine dynamic page title based on current file
$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitles = [
    'index.php'              => 'Dashboard',
    'edit_employee.php'      => 'Edit Employee',
    'admin_approval.php'     => 'Approval Center',
    'system_recovery.php'    => 'System Recovery',
    'analytics.php'          => 'Workforce Analytics',
    'tracker.php'            => 'Compliance Tracker',
    'performance_review.php' => 'Performance Reviews',
    'maintenance_log.php'    => 'Hardware Maintenance',
    'activity_logs.php'      => 'Security Audit Trail',
    'access_review.php'      => 'User Access Review',
    'my_requests.php'        => 'My Requests',
    'profile_settings.php'   => 'Profile Settings'
];
$displayTitle = $pageTitles[$currentPage] ?? 'TESP HR 201 System';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?php echo $displayTitle; ?> | TESP HR 201 System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
    $faviconPath = '../uploads/favicon.png';
    if (!file_exists(__DIR__ . '/../uploads/favicon.png')) $faviconPath = '../uploads/tesp-logo.png';
    $faviconUrl = $faviconPath . '?v=' . (file_exists(__DIR__ . '/' . $faviconPath) ? filemtime(__DIR__ . '/' . $faviconPath) : time());
    ?>
    <link rel="icon" type="image/png" href="<?= $faviconUrl ?>">
    <link rel="shortcut icon" type="image/png" href="<?= $faviconUrl ?>">
    <link rel="apple-touch-icon" href="<?= $faviconUrl ?>">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link href="assets/icons/bootstrap-icons.css" rel="stylesheet">
    <script src="assets/chart.min.js"></script>
    <script src="assets/sweetalert2.all.min.js"></script>
    <script src="assets/dark_mode.js"></script>
    <style>
        :root {
            --bg: #f4f6f9;
            --card-border: #e9ecef;
            --accent: #2a5298;
        }

        [data-bs-theme=dark] {
            --bg: #212529;
            --card-border: #495057;
            --accent: #6ea8fe;
        }

        body {
            background: var(--bg);
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        /* Global Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .modal-backdrop {
            animation: fadeIn 0.3s ease !important;
        }

        .modal {
            animation: scaleIn 0.3s ease !important;
        }

        .toast {
            animation: slideInLeft 0.3s ease;
        }

        .alert {
            animation: fadeInUp 0.3s ease;
        }

        .container {
            max-width: 1200px;
        }

        .navbar-brand {
            font-weight: 700;
            letter-spacing: .2px;
        }

        .shadow-soft {
            box-shadow: 0 10px 30px rgba(0, 0, 0, .05);
        }

        .avatar-circle {
            width: 86px;
            height: 86px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #fff;
            box-shadow: 0 6px 12px rgba(0, 0, 0, .12);
            background: #fff;
        }

        .employee-card {
            cursor: pointer;
            transition: transform 0.25s ease, box-shadow 0.25s ease, background-color 0.3s ease, border-color 0.3s ease;
            border: 1px solid var(--card-border);
        }

        .employee-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 1rem 2rem rgba(0, 0, 0, .12);
        }

        .status-active {
            border-top: 6px solid #198754;
        }

        .status-agency {
            border-top: 6px solid #ffc107;
        }

        .status-sick {
            border-top: 6px solid #dc3545;
        }

        .status-terminated {
            border-top: 6px solid #000;
        }

        .modal-header-custom {
            background: linear-gradient(135deg, #1e3c72 0%, var(--accent) 100%);
            color: #fff;
        }

        .info-label {
            font-weight: 600;
            color: #6c757d;
            font-size: .8rem;
            text-transform: uppercase;
        }

        .preview-box {
            width: 100%;
            height: 100%;
            min-height: 500px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border-radius: 8px;
            overflow: hidden;
        }

        .preview-iframe,
        .preview-img {
            width: 100%;
            height: 100%;
            border: none;
            object-fit: contain;
        }

        .page-link {
            border-radius: .4rem;
        }

        .dropdown-menu {
            border-radius: .75rem;
        }

        .card {
            border-radius: .75rem;
        }

        .white-space-normal {
            white-space: normal;
        }

        .extra-small {
            font-size: .75rem;
        }

        .highlight-target {
            background: #fff3cd !important;
            border-color: #ffecb5 !important;
        }

        /* Bootstrap Modal Animations */
        .modal.fade .modal-dialog {
            transition: transform 0.3s ease-out, opacity 0.3s ease-out;
        }

        .modal.show .modal-dialog {
            animation: scaleIn 0.3s ease !important;
        }

        /* Smooth transitions for all interactive elements */
        .btn,
        .form-control,
        .form-select,
        .dropdown-item {
            transition: all 0.3s ease;
        }

        .btn:hover,
        .btn:focus {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15) !important;
        }

        /* Smooth collapse animations */
        .collapse {
            transition: all 0.3s ease;
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 px-3">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php"><i class="bi bi-house-door-fill me-2"></i> Dashboard</a>

            <button onclick="history.back()" class="btn btn-sm btn-outline-light me-2 no-print" title="Go Back">
                <i class="bi bi-arrow-left"></i> Back
            </button>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarContent">
                <div class="d-flex align-items-center ms-auto mt-3 mt-lg-0">
                    <!-- Session Timer -->
                    <div class="text-white me-3 small d-none d-md-block" title="Time until auto-logout">
                        <i class="bi bi-hourglass-split"></i> <span id="sessionTimer" class="fw-bold font-monospace"><?php echo floor($clientTimeout / 60) . ':' . str_pad($clientTimeout % 60, 2, '0', STR_PAD_LEFT); ?></span>
                    </div>

                    <!-- Dark Mode Toggle -->
                    <button id="darkModeToggle" class="btn btn-sm btn-outline-light me-3 border-0" title="Toggle Dark Mode">
                        <i class="bi bi-moon-stars-fill"></i>
                    </button>

                    <!-- [NEW] Sync Spinner -->
                    <div id="sync-spinner" class="spinner-border spinner-border-sm text-warning me-3" role="status" style="display:none;" title="Syncing Data...">
                        <span class="visually-hidden">Loading...</span>
                    </div>

                    <!-- Notifications dropdown -->
                    <div class="dropdown me-3">
                        <a class="text-white position-relative" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-bell-fill fs-5"></i>
                            <?php if ($notifCount > 0): ?>
                                <?php
                                // [UX] Color code the badge: Red for new messages, Yellow for pending actions only
                                $badgeClass = ($msgCount > 0) ? 'bg-danger' : 'bg-warning text-dark';
                                ?>
                                <span id="notifyBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill <?php echo $badgeClass; ?>">
                                    <?php echo $notifCount; ?>
                                </span>
                            <?php else: ?>
                                <span id="notifyBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display:none">0</span>
                            <?php endif; ?>
                        </a>

                        <ul id="notifyList" class="dropdown-menu dropdown-menu-end shadow" style="width: 350px; max-height: 400px; overflow-y: auto;">
                            <li class="dropdown-header d-flex justify-content-between align-items-center">
                                <span>Notifications</span>
                                <?php if (count($db_notifs ?? []) > 0): // Use $db_notifs from index.php 
                                ?>
                                    <form method="POST" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                                        <button name="clear_notifs" class="btn btn-link btn-sm text-decoration-none p-0" style="font-size: 0.8rem;">Clear Read</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                            <?php if (!empty($doc_alerts)): ?>
                                <li class="bg-light p-2 text-center small fw-bold text-danger border-bottom border-top">
                                    <i class="bi bi-exclamation-circle-fill"></i> Action Required (<?php echo count($doc_alerts ?? []); ?>)
                                </li>
                            <?php endif; ?>
                            <li>
                                <hr class="dropdown-divider">
                            </li>

                            <?php if (count($all_notifications ?? []) > 0): ?>
                                <?php foreach ($all_notifications as $n): ?>
                                    <?php
                                    $backupFile = null;
                                    if (($n['source'] ?? '') === 'expiry') {
                                        $icon = "bi-exclamation-triangle-fill text-warning";
                                        $link = "index.php?search=" . urlencode($n['emp_search']) . "&resolve_doc=" . urlencode((string)$n['link_id']) . "&doc_name=" . urlencode($n['doc_name']);
                                        $clickableClass = "list-group-item-action";
                                    } elseif (($n['source'] ?? '') === 'request') {
                                        $icon = "bi-clipboard-data-fill text-primary";
                                        $link = "admin_approval.php";
                                        $clickableClass = "list-group-item-action";
                                    } elseif (($n['type'] ?? '') === 'success') {
                                        $icon = "bi-check-circle-fill text-success";
                                        $link = "#";
                                        $clickableClass = "";

                                        // [NEW] Check for Backup Notification to add Restore Button
                                        if (strpos($n['title'], 'Backup') !== false && preg_match('/:\s*([a-zA-Z0-9_\-\.]+\.zip)/', $n['message'], $matches)) {
                                            $backupFile = $matches[1];
                                        }
                                    } else {
                                        $icon = "bi-info-circle-fill text-info";
                                        $link = "#";
                                        $clickableClass = "";
                                    }
                                    ?>
                                    <li>
                                        <a href="<?php echo $link; ?>" class="dropdown-item white-space-normal <?php echo $clickableClass; ?>">
                                            <div class="d-flex align-items-start">
                                                <i class="bi <?php echo $icon; ?> fs-4 me-2"></i>
                                                <div class="w-100">
                                                    <h6 class="mb-0 small fw-bold"><?php echo h($n['title']); ?></h6>
                                                    <p class="mb-1 small text-muted" style="font-size: 0.85rem;"><?php echo h($n['message']); ?></p>
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <small class="text-secondary" style="font-size: 0.7rem;">
                                                            <?php echo (($n['source'] ?? '') === 'expiry') ? 'Action Required' : date('M d, h:i A', strtotime($n['created_at'])); ?>
                                                        </small>
                                                        <?php if ($backupFile && $userRole === 'ADMIN'): ?>
                                                            <button onclick="event.preventDefault(); window.location.href='manager_user.php?restore_target=<?php echo urlencode($backupFile); ?>'" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 0.7rem; position: relative; z-index: 2;">
                                                                <i class="bi bi-arrow-counterclockwise"></i> Restore
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </li>
                                    <li>
                                        <hr class="dropdown-divider">
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="p-3 text-center text-muted"><small>No new notifications</small></li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- User Menu -->
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" data-bs-toggle="dropdown">
                            <strong><?php echo h($_SESSION['username'] ?? 'User'); ?></strong>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <?php if (in_array($userRole, ['ADMIN', 'MANAGER'])): ?>
                                <li><a class="dropdown-item fw-bold text-primary" href="manager_dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Manager Dashboard</a></li>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                            <?php endif; ?>
                            <?php if ($userRole === 'ADMIN'): ?>
                                <li><a class="dropdown-item" href="settings.php"><i class="bi bi-sliders me-2"></i> System Settings</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="profile_settings.php"><i class="bi bi-gear me-2"></i> Change Password</a></li>
                            <?php if ($userRole === 'ADMIN'): ?>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li><a class="dropdown-item" href="manager_user.php"><i class="bi bi-people-fill me-2"></i> Manage Users</a></li>
                                <li><a class="dropdown-item" href="access_review.php"><i class="bi bi-shield-check me-2"></i> Access Reviews</a></li>
                            <?php endif; ?>
                            <?php if (in_array($userRole, ['ADMIN', 'MANAGER'])): ?>
                                <li><a class="dropdown-item" href="activity_logs.php"><i class="bi bi-shield-lock-fill me-2 text-danger"></i> Activity Logs</a></li>
                            <?php endif; ?>
                            <?php if ($userRole === 'STAFF'): ?>
                                <li><a class="dropdown-item" href="my_requests.php"><i class="bi bi-clock-history me-2 text-primary"></i> My Requests</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item" href="help.php"><i class="bi bi-question-circle-fill me-2 text-info"></i> User Manual</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <script>
        // [UI REPAIR] Global Notification Listener for SweetAlert2
        document.addEventListener("DOMContentLoaded", function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('msg') || urlParams.has('error')) {
                const msgText = urlParams.get('msg') || urlParams.get('error');
                const isError = urlParams.has('error') || msgText.toLowerCase().includes('failed') || msgText.toLowerCase().includes('error');

                Swal.fire({
                    icon: isError ? 'error' : 'success',
                    title: isError ? 'Process Error' : 'Success',
                    text: msgText,
                    timer: isError ? 5000 : 3000,
                    showConfirmButton: true
                });

                // Clean URL params to prevent re-triggering on refresh
                if (window.history.replaceState) {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('msg');
                    url.searchParams.delete('error');
                    window.history.replaceState(null, null, url.toString());
                }
            }
        });

        // [SECURITY] Centralized Global Auto-Logout Timer (Client-Side)
        document.addEventListener("DOMContentLoaded", function() {
            const INACTIVITY_LIMIT_MS = <?php echo (int)($clientTimeout ?? 900) * 1000; ?>;
            let remainingMs = INACTIVITY_LIMIT_MS;

            function updateTimer() {
                if (remainingMs > 0) remainingMs -= 1000;
                if (remainingMs <= 0) {
                    window.location.href = 'logout.php?msg=Session_Expired_Auto';
                    return;
                }
                const totalSeconds = Math.floor(remainingMs / 1000);
                const m = Math.floor(totalSeconds / 60);
                const s = totalSeconds % 60;
                const text = `${m}:${s.toString().padStart(2, '0')}`;
                const timerEl = document.getElementById('sessionTimer');
                if (timerEl) {
                    timerEl.innerText = text;
                    if (remainingMs < 120000) timerEl.classList.add('text-danger');
                    else timerEl.classList.remove('text-danger');
                }
            }

            function resetTimer() {
                remainingMs = INACTIVITY_LIMIT_MS;
            }

            setInterval(updateTimer, 1000);
            window.addEventListener('mousemove', resetTimer);
            window.addEventListener('keydown', resetTimer);
            window.addEventListener('click', resetTimer);
            window.addEventListener('scroll', resetTimer);
        });
    </script>
    <?php // --- END: UI REPAIR --- 
    ?>