<?php
// public/recruitment.php
require '../config/db.php';
require '../src/Security.php';
session_start();

// Security: HR, Manager, and Admin only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    header("Location: index.php");
    exit;
}

$security = new Security($pdo);
$csrf_token = $security->generateCSRF();

// [FIX] Handle Session Messages (Prevents duplication on reload)
$msg = $_SESSION['msg'] ?? "";
$error = $_SESSION['error'] ?? "";
unset($_SESSION['msg'], $_SESSION['error']);

// [NEW] Fetch Roles for Dropdown (System Roles > Employee Jobs)
$jobTitles = [];
try {
    $jobTitles = $pdo->query("SELECT name FROM system_roles ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
}
if (empty($jobTitles)) {
    $jobTitles = $pdo->query("SELECT DISTINCT job_title FROM employees WHERE job_title != '' ORDER BY job_title ASC")->fetchAll(PDO::FETCH_COLUMN);
}

// [NEW] Filters
$filterStatus = $_GET['status'] ?? '';
$filterMonth  = $_GET['month'] ?? '';
$filterWeek   = $_GET['week'] ?? '';

// [FIX] Preserve filters on redirect
$redirectParams = [];
if ($filterStatus) $redirectParams['status'] = $filterStatus;
if ($filterMonth) $redirectParams['month'] = $filterMonth;
if ($filterWeek) $redirectParams['week'] = $filterWeek;
$redirectUrl = "recruitment.php" . (!empty($redirectParams) ? "?" . http_build_query($redirectParams) : "");

try {
    // --- HANDLE ADDING CANDIDATE ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_candidate'])) {
        $security->checkCSRF($_POST['csrf_token']);
        $first = trim($_POST['first_name']);
        $last = trim($_POST['last_name']);
        $pos = trim($_POST['position_applied']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone_number']);
        $date = $_POST['application_date'];

        // [SECURITY] Validation
        if (empty($first)) $error = "First Name is required.";
        elseif (empty($last)) $error = "Last Name is required.";
        elseif (empty($pos)) $error = "Position is required.";
        elseif (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $error = "Valid application date is required.";
        elseif (strlen($first) > 50) $error = "First Name is too long (Max 50 chars).";
        elseif (strlen($last) > 50) $error = "Last Name is too long (Max 50 chars).";
        elseif (strlen($pos) > 100) $error = "Position is too long (Max 100 chars).";
        elseif (strlen($email) > 100) $error = "Email is too long.";
        elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = "Invalid email format.";
        elseif (strlen($phone) > 25) $error = "Phone number too long.";
        elseif (!preg_match("/^[a-zA-Z\s\-\.\']+$/", $first)) $error = "First Name contains invalid characters.";
        elseif (!preg_match("/^[a-zA-Z\s\-\.\']+$/", $last)) $error = "Last Name contains invalid characters.";
        elseif (!preg_match("/^[a-zA-Z0-9\s\-\.\,\(\)\/\&']+$/", $pos)) $error = "Position contains invalid characters.";

        if (empty($error) && $first && $last && $pos && $date) {
            $stmt = $pdo->prepare("INSERT INTO candidates (first_name, last_name, position_applied, email, phone_number, application_date, last_follow_up) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$first, $last, $pos, $email, $phone, $date, $date]);
            // [FIX] Post-Redirect-Get to prevent duplication on reload
            $_SESSION['msg'] = "✅ Candidate added successfully.";
            header("Location: " . $redirectUrl);
            exit;
        }
    }

    // --- HANDLE EDIT CANDIDATE ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_candidate'])) {
        $security->checkCSRF($_POST['csrf_token']);
        $id = (int)$_POST['candidate_id'];
        $status = $_POST['status'];
        $notes = trim($_POST['notes']);
        $reject_reason = trim($_POST['rejection_reason'] ?? '');
        $is_blacklisted = isset($_POST['is_blacklisted']) ? 1 : 0;

        $allowedStatuses = ['New Applicant', 'Screening', 'Interviewed', 'Hired', 'Rejected'];
        if (!in_array($status, $allowedStatuses, true)) {
            $error = "Invalid status value.";
        } elseif (strlen($notes) > 1000) {
            $error = "Notes are too long (Max 1000 chars).";
        } else {
            $stmt = $pdo->prepare("UPDATE candidates SET status = ?, notes = ?, rejection_reason = ?, is_blacklisted = ?, last_follow_up = NOW() WHERE id = ?");
            $stmt->execute([$status, $notes, $reject_reason, $is_blacklisted, $id]);

            $_SESSION['msg'] = "✅ Candidate status updated.";
            header("Location: " . $redirectUrl);
            exit;
        }
    }

    // --- HANDLE DELETE CANDIDATE ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_candidate'])) {
        $security->checkCSRF($_POST['csrf_token']);
        $id = (int)$_POST['candidate_id'];
        $pdo->prepare("DELETE FROM candidates WHERE id = ?")->execute([$id]);
        $_SESSION['msg'] = "🗑️ Candidate deleted successfully.";
        header("Location: " . $redirectUrl);
        exit;
    }

    // --- HANDLE SCHEDULE INTERVIEW (EMAIL) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_interview'])) {
        $security->checkCSRF($_POST['csrf_token']);
        $id = (int)$_POST['candidate_id'];
        $intDate = $_POST['interview_date'];
        $message = trim($_POST['message']);

        // Validate datetime format
        if (empty($intDate) || !strtotime($intDate)) {
            $_SESSION['error'] = "❌ Invalid interview date format.";
            header("Location: " . $redirectUrl);
            exit;
        }

        // Fetch candidate email
        $stmt = $pdo->prepare("SELECT * FROM candidates WHERE id = ?");
        $stmt->execute([$id]);
        $cand = $stmt->fetch();

        if ($cand && !empty($cand['email'])) {
            $to = $cand['email'];
            $subject = "Interview Invitation - " . $cand['position_applied'];
            $headers = "From: HR Department <no-reply@company.com>";

            // [NEW] System Notification
            try {
                $notifMsg = "Interview scheduled with " . $cand['first_name'] . " " . $cand['last_name'] . " for " . date('M d, Y h:i A', strtotime($intDate));
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Interview Scheduled', ?, 'info')")->execute([$_SESSION['user_id'], $notifMsg]);
            } catch (PDOException $e) {
                // Notification table may not exist; log but don't fail the operation
                error_log("Failed to create notification: " . $e->getMessage());
            }

            // Simple mail send (configure SMTP in php.ini for production)
            if (mail($to, $subject, $message, $headers)) {
                $pdo->prepare("UPDATE candidates SET status = 'Interviewed', interview_date = ?, last_follow_up = NOW() WHERE id = ?")->execute([$intDate, $id]);

                $_SESSION['msg'] = "✅ Interview scheduled & email sent to " . $cand['email'];
            } else {
                $_SESSION['error'] = "❌ Failed to send email. Check server settings.";
            }
        } else {
            $_SESSION['error'] = "❌ Candidate has no email address.";
        }
        header("Location: " . $redirectUrl);
        exit;
    }

    // --- HANDLE FOLLOW UP (EMAIL) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_followup'])) {
        $security->checkCSRF($_POST['csrf_token']);
        $id = (int)$_POST['candidate_id'];
        $message = trim($_POST['message']);

        $stmt = $pdo->prepare("SELECT * FROM candidates WHERE id = ?");
        $stmt->execute([$id]);
        $cand = $stmt->fetch();

        if ($cand && !empty($cand['email'])) {
            $to = $cand['email'];
            $subject = "Follow Up - " . $cand['position_applied'];
            $headers = "From: HR Department <no-reply@company.com>";

            if (mail($to, $subject, $message, $headers)) {
                $pdo->prepare("UPDATE candidates SET last_follow_up = NOW() WHERE id = ?")->execute([$id]);
                $_SESSION['msg'] = "✅ Follow-up email sent to " . $cand['email'];
            } else {
                $_SESSION['error'] = "❌ Failed to send email.";
            }
        }
        header("Location: " . $redirectUrl);
        exit;
    }

    // --- FETCH ANALYTICS DATA ---
    // 1. Total Candidates
    $total = $pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
    // 2. Hired Candidates
    $hired = $pdo->query("SELECT COUNT(*) FROM candidates WHERE status = 'Hired'")->fetchColumn();
    // 3. Needs Follow-up (More than 3 days ago, and not Hired/Rejected)
    $needs_action = $pdo->query("SELECT COUNT(*) FROM candidates WHERE last_follow_up < DATE_SUB(CURDATE(), INTERVAL 3 DAY) AND status NOT IN ('Hired', 'Rejected')")->fetchColumn();

    // 4. Chart Data (Count by Status)
    $chartData = $pdo->query("SELECT status, COUNT(*) as count FROM candidates GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    $labels = [];
    $data = [];
    foreach ($chartData as $row) {
        $labels[] = $row['status'];
        $data[] = $row['count'];
    }

    // --- FETCH TABLE DATA ---
    $sql = "SELECT * FROM candidates WHERE 1=1";
    $params = [];
    if ($filterStatus) {
        $sql .= " AND status = ?";
        $params[] = $filterStatus;
    }
    if ($filterMonth) {
        $sql .= " AND DATE_FORMAT(application_date, '%Y-%m') = ?";
        $params[] = $filterMonth;
    }
    if ($filterWeek) {
        // Validate week format (YYYY-Www)
        if (!preg_match('/^\d{4}-W\d{2}$/', $filterWeek)) {
            $filterWeek = ''; // Ignore invalid format
        } else {
            $year = (int)substr($filterWeek, 0, 4);
            $week = (int)substr($filterWeek, 6);
            $dto = new DateTime();
            $dto->setISODate($year, $week);
            $startDate = $dto->format('Y-m-d');
            $dto->modify('+6 days');
            $endDate = $dto->format('Y-m-d');
            $sql .= " AND application_date BETWEEN ? AND ?";
            $params[] = $startDate;
            $params[] = $endDate;
        }
    }
    $sql .= " ORDER BY application_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll();
} catch (PDOException $e) {
    // [FIX] Handle missing table error gracefully
    if ($e->getCode() == '42S02' || strpos($e->getMessage(), "doesn't exist") !== false) {
        $isAdmin = ($_SESSION['role'] ?? '') === 'ADMIN';
?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <title>Setup Required</title>
            <link href="assets/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
        </head>

        <body class="bg-light d-flex align-items-center justify-content-center vh-100">
            <div class="card shadow-sm border-danger" style="max-width: 500px;">
                <div class="card-body text-center p-5">
                    <div class="text-danger mb-3"><i class="bi bi-exclamation-octagon display-1"></i></div>
                    <h2 class="text-danger fw-bold">Database Setup Required</h2>
                    <p class="lead fs-6 mt-3">The <strong>Recruitment</strong> module cannot be loaded because the database table <code>candidates</code> is missing.</p>

                    <?php if ($isAdmin): ?>
                        <p class="text-muted small">As an Admin, you can fix this automatically.</p>
                        <a href="db_status.php" class="btn btn-primary fw-bold w-100"><i class="bi bi-magic"></i> Run Auto-Fix</a>
                    <?php else: ?>
                        <div class="alert alert-warning small">Please contact your System Administrator to run the database update.</div>
                    <?php endif; ?>

                    <a href="index.php" class="btn btn-outline-secondary w-100 mt-2">Back to Dashboard</a>
                </div>
            </div>
        </body>

        </html>
<?php
        exit;
    }
    throw $e; // Re-throw if it's not a missing table error
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Recruitment Dashboard</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/chart.min.js"></script>
    <style>
        /* =========================================
           PRINT TO PDF STYLES
           ========================================= */
        @media print {
            @page {
                size: landscape;
                margin: 0.5in;
            }

            nav,
            .btn,
            .modal,
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
            }

            .card {
                border: none !important;
                box-shadow: none !important;
            }

            table {
                border-collapse: collapse !important;
                width: 100% !important;
            }

            th,
            td {
                border: 1px solid #dee2e6 !important;
                font-size: 9pt;
                padding: 4px;
                vertical-align: middle;
            }

            /* PDF Report Title */
            body::before {
                content: "Recruitment Pipeline Report";
                display: block;
                text-align: center;
                font-size: 18pt;
                font-weight: bold;
                margin-bottom: 20px;
            }

            /* Hide Action Column in Print */
            .no-print-col {
                display: none !important;
            }

            /* Ensure table fits */
            .table {
                width: 100% !important;
                table-layout: fixed;
            }

            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <span class="navbar-text text-white"><i class="bi bi-person-lines-fill"></i> Recruitment & ATS</span>
        </div>
    </nav>

    <div class="container">
        <?php if ($msg): ?>
            <div class="alert alert-success no-print"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger no-print"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card bg-primary text-white shadow-sm h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Total Candidates</h5>
                        <h2 class="display-4 fw-bold"><?php echo $total; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card bg-success text-white shadow-sm h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Successfully Hired</h5>
                        <h2 class="display-4 fw-bold"><?php echo $hired; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card bg-danger text-white shadow-sm h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Action Required (Follow-up)</h5>
                        <h2 class="display-4 fw-bold"><?php echo $needs_action; ?></h2>
                        <small>No contact in 3+ days</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-bold">Pipeline Breakdown</div>
                    <div class="card-body">
                        <canvas id="pipelineChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="col-md-8 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <div class="d-flex gap-2 align-items-center">
                            <span class="fw-bold">Candidate Tracker</span>
                            <!-- FILTERS -->
                            <form method="GET" class="d-flex gap-2 ms-3">
                                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">All Status</option>
                                    <option value="New Applicant" <?php if ($filterStatus == 'New Applicant') echo 'selected'; ?>>New Applicant</option>
                                    <option value="Screening" <?php if ($filterStatus == 'Screening') echo 'selected'; ?>>Screening</option>
                                    <option value="Interviewed" <?php if ($filterStatus == 'Interviewed') echo 'selected'; ?>>Interviewed</option>
                                    <option value="Hired" <?php if ($filterStatus == 'Hired') echo 'selected'; ?>>Hired</option>
                                    <option value="Rejected" <?php if ($filterStatus == 'Rejected') echo 'selected'; ?>>Rejected</option>
                                </select>
                                <input type="month" name="month" class="form-control form-control-sm" value="<?php echo $filterMonth; ?>" onchange="this.form.submit()">
                                <input type="week" name="week" class="form-control form-control-sm" value="<?php echo $filterWeek; ?>" onchange="this.form.submit()">
                                <?php if ($filterStatus || $filterMonth || $filterWeek): ?>
                                    <a href="recruitment.php" class="btn btn-sm btn-outline-secondary text-nowrap"><i class="bi bi-x-lg"></i> Clear Filters</a>
                                <?php endif; ?>
                            </form>
                        </div>

                        <div class="no-print d-flex gap-2">
                            <a href="export_recruitment.php" class="btn btn-sm btn-success"><i class="bi bi-file-earmark-excel"></i> Excel</a>
                            <!-- [NEW] Dedicated Print Page -->
                            <a href="print_recruitment.php?status=<?php echo urlencode($filterStatus); ?>&month=<?php echo urlencode($filterMonth); ?>&week=<?php echo urlencode($filterWeek); ?>" target="_blank" class="btn btn-sm btn-dark"><i class="bi bi-printer"></i> Print List</a>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg"></i> Add</button>
                        </div>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Position</th>
                                    <th>Contact Info</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                    <th>Last Follow-up</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($candidates as $c):
                                    // Calculate days since last follow-up
                                    $follow_up_date = strtotime($c['last_follow_up']);
                                    $days_ago = round((time() - $follow_up_date) / (60 * 60 * 24));

                                    // Highlight logic
                                    $row_class = "";
                                    $badge = "bg-secondary";

                                    if ($c['status'] == 'Hired') $badge = "bg-success";
                                    if ($c['status'] == 'Interviewed') $badge = "bg-info";
                                    if ($c['status'] == 'Rejected') $badge = "bg-dark";

                                    if ($days_ago > 3 && !in_array($c['status'], ['Hired', 'Rejected'])) {
                                        $row_class = "table-danger"; // Red warning!
                                    }
                                    $isBlacklisted = !empty($c['is_blacklisted']);
                                ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td class="fw-bold"><?php echo htmlspecialchars($c['last_name'] . ', ' . $c['first_name']); ?></td>
                                        <td><?php echo htmlspecialchars($c['position_applied']); ?></td>
                                        <td class="small">
                                            <?php if ($c['phone_number']): ?><div><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($c['phone_number']); ?></div><?php endif; ?>
                                            <?php if ($c['email']): ?><div><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($c['email']); ?></div><?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($c['status']); ?></span>
                                            <?php if ($isBlacklisted): ?>
                                                <span class="badge bg-dark text-danger border border-danger"><i class="bi bi-slash-circle"></i> BLACKLISTED</span>
                                            <?php endif; ?>
                                            <?php if ($c['status'] == 'Rejected' && !empty($c['rejection_reason'])): ?>
                                                <div class="small text-danger mt-1">Reason: <?php echo htmlspecialchars($c['rejection_reason']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($c['interview_date'])): ?>
                                                <div class="small text-primary mt-1"><i class="bi bi-calendar-event"></i> <?php echo date('M d H:i', strtotime($c['interview_date'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <?php if ($isBlacklisted): ?>
                                                    <button class="btn btn-secondary disabled" title="Cannot Hire: Candidate is Blacklisted" disabled><i class="bi bi-person-x-fill"></i></button>
                                                <?php else: ?>
                                                    <a href="add_employee.php?first_name=<?php echo urlencode($c['first_name']); ?>&last_name=<?php echo urlencode($c['last_name']); ?>&job_title=<?php echo urlencode($c['position_applied']); ?>&email=<?php echo urlencode($c['email']); ?>&contact_number=<?php echo urlencode($c['phone_number']); ?>&hire_date=<?php echo date('Y-m-d'); ?>"
                                                        class="btn btn-outline-success"
                                                        title="Hire & Add to Employee Database">
                                                        <i class="bi bi-person-check-fill"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-outline-primary" title="Schedule / Reschedule Interview" onclick='openScheduleModal(<?php echo $c['id']; ?>, <?php echo json_encode($c['email'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($c['first_name'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($c['interview_date'] ?? '', JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="bi bi-calendar-event"></i></button>
                                                <button type="button" class="btn btn-outline-info" title="Send Follow Up" onclick='openFollowUpModal(<?php echo $c['id']; ?>, <?php echo json_encode($c['email'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($c['first_name'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="bi bi-envelope-arrow-up"></i></button>
                                                <button type="button" class="btn btn-outline-secondary" title="Edit Status" onclick='editCandidate(<?php echo $c['id']; ?>, <?php echo json_encode($c['status'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($c['notes'] ?? "", JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($c['rejection_reason'] ?? "", JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo $isBlacklisted ? 1 : 0; ?>)'><i class="bi bi-pencil"></i></button>
                                                <button type="button" class="btn btn-outline-danger" title="Delete" onclick="deleteCandidate(<?php echo $c['id']; ?>)"><i class="bi bi-trash"></i></button>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', $follow_up_date); ?>
                                            <?php if ($row_class == "table-danger"): ?>
                                                <br><small class="text-danger fw-bold"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo $days_ago; ?> days ago!</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add Candidate</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="add_candidate" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                    <div class="row g-2 mb-3">
                        <div class="col"><input type="text" name="first_name" class="form-control" placeholder="First Name" required maxlength="50" pattern="[a-zA-Z\s\-\.\']+" title="Letters, spaces, dots, dashes, apostrophes" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\']/g, '')"></div>
                        <div class="col"><input type="text" name="last_name" class="form-control" placeholder="Last Name" required maxlength="50" pattern="[a-zA-Z\s\-\.\']+" title="Letters, spaces, dots, dashes, apostrophes" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\']/g, '')"></div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col">
                            <label class="form-label small fw-bold">Phone Number</label>
                            <input type="text" name="phone_number" class="form-control" placeholder="0912..." maxlength="25" pattern="[0-9+\-\s()\/]+" oninput="this.value = this.value.replace(/[^0-9+\-\s()\/]/g, '')">
                        </div>
                        <div class="col">
                            <label class="form-label small fw-bold">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="user@email.com" maxlength="100">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label>Position Applied For</label>
                        <input type="text" name="position_applied" class="form-control" list="job_list" placeholder="Select or Type Position..." required maxlength="100" pattern="[a-zA-Z0-9\s\-\.\,\(\)\/\&']+" title="Alphanumeric and basic punctuation">
                        <datalist id="job_list">
                            <?php foreach ($jobTitles as $job): ?>
                                <option value="<?php echo htmlspecialchars($job); ?>">
                                <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="mb-3">
                        <label>Application Date</label>
                        <input type="date" name="application_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Save Candidate</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT MODAL -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-info text-dark">
                    <h5 class="modal-title">Update Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="edit_candidate" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="candidate_id" id="edit_id">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" id="edit_status" class="form-select" onchange="toggleRejectionField()">
                            <option value="New Applicant">New Applicant</option>
                            <option value="Screening">Screening</option>
                            <option value="Interviewed">Interviewed</option>
                            <option value="Hired">Hired</option>
                            <option value="Rejected">Rejected</option>
                        </select>
                    </div>
                    <div class="mb-3" id="reject_div" style="display:none;">
                        <label class="form-label text-danger fw-bold">Reason for Rejection</label>
                        <input type="text" name="rejection_reason" id="edit_reject_reason" class="form-control border-danger" placeholder="e.g. Applied to other company, Failed technical exam">
                    </div>
                    <div class="form-check mb-3 p-3 border rounded bg-light">
                        <input class="form-check-input" type="checkbox" name="is_blacklisted" id="edit_blacklist" value="1">
                        <label class="form-check-label fw-bold text-danger" for="edit_blacklist"><i class="bi bi-slash-circle"></i> Blacklist Candidate (Do Not Hire)</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes / Remarks</label>
                        <textarea name="notes" id="edit_notes" class="form-control" rows="3" maxlength="1000" placeholder="Add interview notes or observations..."></textarea>
                        <div class="form-text small text-end">Max 1000 characters</div>
                    </div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-info">Update</button></div>
            </form>
        </div>
    </div>

    <!-- SCHEDULE MODAL -->
    <div class="modal fade" id="scheduleModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="schedModalTitle"><i class="bi bi-envelope"></i> Schedule Interview</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="schedule_interview" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="candidate_id" id="sched_id">

                    <div class="mb-3">
                        <label class="form-label">Interview Date & Time</label>
                        <input type="datetime-local" name="interview_date" id="sched_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email Message</label>
                        <textarea name="message" id="sched_msg" class="form-control" rows="5" required></textarea>
                    </div>
                    <div class="alert alert-info small"><i class="bi bi-info-circle"></i> This will send an email to the candidate.</div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Send Invite</button>
                </div>
            </form>
        </div>
    </div>

    <!-- FOLLOW UP MODAL -->
    <div class="modal fade" id="followUpModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-info text-dark">
                    <h5 class="modal-title"><i class="bi bi-envelope-arrow-up"></i> Send Follow Up</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="send_followup" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="candidate_id" id="follow_id">

                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea name="message" id="follow_msg" class="form-control" rows="5" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-info">Send Email</button>
                </div>
            </form>
        </div>
    </div>

    <!-- DELETE FORM -->
    <form id="deleteForm" method="POST" style="display:none;">
        <input type="hidden" name="delete_candidate" value="1">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="candidate_id" id="del_id">
    </form>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize the Pie Chart
        const ctx = document.getElementById('pipelineChart');
        const pipelineChart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Candidates',
                    data: <?php echo json_encode($data); ?>,
                    backgroundColor: ['#6c757d', '#0dcaf0', '#ffc107', '#198754', '#dc3545', '#0d6efd'],
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false, // Disable animation for print compatibility
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        function editCandidate(id, status, notes, rejectReason, isBlacklisted) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_status').value = status;
            document.getElementById('edit_notes').value = notes;
            document.getElementById('edit_blacklist').checked = (isBlacklisted == 1);

            // Handle Rejection Reason field
            const rejectInput = document.getElementById('edit_reject_reason');
            if (rejectInput) rejectInput.value = rejectReason || '';

            toggleRejectionField();
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }

        function toggleRejectionField() {
            const status = document.getElementById('edit_status').value;
            const rejectDiv = document.getElementById('reject_div');
            if (rejectDiv) {
                rejectDiv.style.display = (status === 'Rejected') ? 'block' : 'none';
            }
        }

        function deleteCandidate(id) {
            if (confirm('Are you sure you want to delete this candidate? This cannot be undone.')) {
                document.getElementById('del_id').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        function openScheduleModal(id, email, name, existingDate) {
            if (!email) {
                alert("This candidate does not have an email address.");
                return;
            }
            document.getElementById('sched_id').value = id;

            const title = existingDate ? "Reschedule Interview" : "Schedule Interview";
            const titleEl = document.getElementById('schedModalTitle');
            if (titleEl) titleEl.innerHTML = `<i class="bi bi-envelope"></i> ${title}`;

            const dateInput = document.getElementById('sched_date');
            if (dateInput) dateInput.value = existingDate ? existingDate.replace(' ', 'T') : '';

            const action = existingDate ? "reschedule" : "schedule";
            document.getElementById('sched_msg').value = `Dear ${name},\n\nWe would like to ${action} your interview at TES Philippines.\n\nPlease confirm your availability.\n\nRegards,\nHR Team`;
            new bootstrap.Modal(document.getElementById('scheduleModal')).show();
        }

        function openFollowUpModal(id, email, name) {
            if (!email) {
                alert("No email address.");
                return;
            }
            document.getElementById('follow_id').value = id;
            document.getElementById('follow_msg').value = `Dear ${name},\n\nWe are following up on your application status.\n\nRegards,\nHR Team`;
            new bootstrap.Modal(document.getElementById('followUpModal')).show();
        }
    </script>
</body>

</html>