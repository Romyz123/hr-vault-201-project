<?php
// header.php

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
}

if (!function_exists('h')) {
    function h($v)
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// [FIX] Ensure doc_alerts and db_notifs are always initialized to avoid 500 errors on count()
$doc_alerts = [];
$db_notifs = [];
$all_notifications = [];
$msgCount = 0;
$notifCount = 0;

require_once __DIR__ . '/options.php';

// [NEW] Normalize user context for shared header logic
$userRole = strtoupper(trim($_SESSION['role'] ?? ''));
$username = trim($_SESSION['username'] ?? 'User');

// [NEW] Auto-fetch settings if not provided by parent page
if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];

    if (!isset($clientTimeout)) {
        try {
            $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
            $clientTimeout = (int)$stmt->fetchColumn() ?: 900;
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
            $chk = $pdo->query("SHOW COLUMNS FROM documents LIKE 'deleted_at'");
            if ($chk->rowCount() > 0) $docQuery .= " AND d.deleted_at IS NULL";

            // [SECURITY] Only filter by owner if not privileged AND the column exists
            $isRestrictedUpload = false;
            $chkUp = $pdo->query("SHOW COLUMNS FROM documents LIKE 'uploaded_by'");
            if ($chkUp->rowCount() > 0 && !in_array($userRole, ['ADMIN', 'MANAGER', 'HR'])) {
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
    $notifCount = $msgCount + count($doc_alerts ?: []);
}

// [NEW] Fetch unread notifications count for the red badge
$unreadNotifCount = 0;
if (isset($_SESSION['user_id'])) {
    $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->execute([$_SESSION['user_id']]);
    $unreadNotifCount = (int)$unreadStmt->fetchColumn();
}

$notificationCount = max($notifCount, $unreadNotifCount);

$csrf_token = $_SESSION['csrf_token'] ?? '';

// [NEW] Robust Logo Discovery Logic
$logo_paths = [
    __DIR__ . '/uploads/tesp-logo.png',
    __DIR__ . '/assets/images/tesp-logo-1.png',
    __DIR__ . '/../uploads/tesp-logo.png'
];
$nav_logo_src = '';
foreach ($logo_paths as $p) {
    if (file_exists($p)) {
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $mime = ($ext === 'png' ? 'image/png' : 'image/jpeg');
        $nav_logo_src = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($p));
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>TESP HR 201 System</title>
    <script nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
        document.documentElement.setAttribute('data-bs-theme', localStorage.getItem('theme') || 'light');
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
    $faviconPath = '../uploads/favicon.png';
    if (!file_exists(__DIR__ . '/../uploads/favicon.png')) $faviconPath = '../uploads/tesp-logo.png';
    $faviconUrl = $faviconPath . '?v=' . (file_exists(__DIR__ . '/' . $faviconPath) ? filemtime(__DIR__ . '/' . $faviconPath) : time());
    ?>
    <link rel="icon" type="image/png" href="<?= $faviconUrl ?>">
    <link rel="shortcut icon" type="image/png" href="<?= $faviconUrl ?>">
    <link rel="apple-touch-icon" href="<?= $faviconUrl ?>">
    <link href="assets/bootstrap.min.css?v=5" rel="stylesheet" nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
    <link href="assets/icons/bootstrap-icons.css?v=5" rel="stylesheet" nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
    <script src="assets/chart.min.js?v=3" nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="assets/sweetalert2.all.min.js?v=3" nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="assets/dark_mode.js?v=7" nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>"></script>
    <style nonce="<?= htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') ?>">
        :root {
            --accent: #2a5298;
        }

        [data-bs-theme=dark] {
            --accent: #6ea8fe;
        }

        body {
            background-color: var(--bs-body-bg);
        }

        /* Theme-aware shadows */
        .shadow-soft {
            box-shadow: 0 10px 30px rgba(0, 0, 0, .05);
        }

        [data-bs-theme=dark] .shadow-soft {
            box-shadow: 0 10px 30px rgba(0, 0, 0, .5) !important;
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
            transition: transform .18s ease, box-shadow .18s ease;
            border: 1px solid var(--bs-border-color);
        }

        .employee-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 1rem 2rem rgba(0, 0, 0, .08);
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
            background: linear-gradient(135deg, #1e3c72 0%, var(--accent, #2a5298) 100%);
            color: #fff;
        }

        .info-label {
            font-weight: 600;
            color: #6c757d;
            font-size: .8rem;
            text-transform: uppercase;
        }

        .preview-box {
            height: 520px;
            border: 2px dashed #dee2e6;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border-radius: .5rem;
            color: #6c757d;
        }

        .preview-iframe {
            width: 100%;
            height: 100%;
            border: 0;
            border-radius: .5rem;
        }

        .preview-img {
            max-width: 100%;
            max-height: 100%;
            border-radius: .5rem;
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
    </style>
</head>

<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 px-3">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <?php if ($nav_logo_src): ?>
                    <img src="<?= $nav_logo_src ?>" alt="Logo" width="30" height="30" class="d-inline-block align-text-top rounded-circle me-2">
                <?php endif; ?>
                <span>Dashboard</span>
            </a>

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
                            <?php if ($notificationCount > 0): ?>
                                <?php
                                // [UX] Color code the badge: Red for new messages, Yellow for pending actions only
                                $badgeClass = ($msgCount > 0) ? 'bg-danger' : 'bg-warning text-dark';
                                ?>
                                <span id="notifyBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill <?= $badgeClass; ?>">
                                    <?php echo $notificationCount; ?>
                                </span>
                            <?php else: ?>
                                <span id="notifyBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display:none">0</span>
                            <?php endif; ?>
                        </a>

                        <ul id="notifyList" class="dropdown-menu dropdown-menu-end shadow bg-body" style="width: 350px; max-height: 400px; overflow-y: auto;">
                            <li class="dropdown-header d-flex justify-content-between align-items-center">
                                <span>Notifications</span>
                                <?php if (count($db_notifs ?? []) > 0): // Use $db_notifs from index.php 
                                ?>
                                    <form method="POST" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['csrf_token'] ?? ''); ?>">
                                        <button type="submit" name="clear_notifs" class="btn btn-link btn-sm text-decoration-none p-0" style="font-size: 0.8rem;">Clear Read</button>
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
                        <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" id="userMenuDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <strong><i class="bi bi-person-circle me-1"></i> <?php echo h($_SESSION['username'] ?? 'User'); ?></strong>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userMenuDropdown">
                            <li>
                                <h6 class="dropdown-header">User Controls</h6>
                            </li>
                            <li><a class="dropdown-item" href="profile_settings.php"><i class="bi bi-person-gear me-2"></i> Profile Settings</a></li>


                            <?php if ($userRole === 'ADMIN'): ?>
                                <li>
                                    <hr class="dropdown-divider">
                                </li>
                                <li>
                                    <h6 class="dropdown-header text-danger">Admin Panel</h6>
                                </li>
                                <li><a class="dropdown-item" href="settings.php"><i class="bi bi-sliders me-2"></i> System Settings</a></li>
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

    <script nonce="<?= $cspNonce ?>">
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

            // [NEW] Mark notifications as read when dropdown is shown
            const bellDropdownIcon = document.querySelector('.bi-bell-fill');
            if (bellDropdownIcon) {
                const bellDropdown = bellDropdownIcon.closest('.dropdown');
                bellDropdown.addEventListener('show.bs.dropdown', function() {
                    const badge = document.getElementById('notifyBadge');
                    if (badge && badge.style.display !== 'none' && badge.innerText !== '0') {
                        fetch('api/mark_read.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: 'csrf_token=' + encodeURIComponent('<?php echo $_SESSION['csrf_token'] ?? ''; ?>')
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.status === 'success') {
                                    badge.innerText = '0';
                                    badge.style.display = 'none';
                                }
                            })
                            .catch(err => console.error('Mark as read failed:', err));
                    }
                });
            }
        });

        // [SECURITY] AJAX Notification Sync (Refresh unread count every 60s)
        function syncNotifications() {
            fetch('api/get_updates.php')
                .then(response => response.json())
                .then(data => {
                    const badge = document.getElementById('notifyBadge');
                    if (data.status === 'success' && badge) {
                        const currentCount = parseInt(badge.innerText, 10) || 0;
                        const remoteCount = parseInt(data.unreadCount, 10) || 0;
                        const displayCount = Math.max(currentCount, remoteCount);
                        badge.innerText = displayCount;
                        badge.style.display = displayCount > 0 ? 'block' : 'none';
                    }
                })
                .catch(err => console.error('Notification sync failed:', err));
        }

        // Run every 60 seconds
        setInterval(syncNotifications, 60000);
    </script>