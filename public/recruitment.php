<?php
// public/recruitment.php
require '../config/db.php';
require '../src/Security.php';
require '../src/Validator.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

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

// ==============================================================
// [MHI SECURE SMS MODULE] 
// Replaces insecure Gmail SMTP with direct Telecom API Routing
// Recommended Provider: Semaphore.co (Philippines)
// ==============================================================
function sendSMS($phone, $message)
{
    // Access the global $_ENV array where config.php is loaded
    global $_ENV;

    // Clean phone number
    $phone = preg_replace('/[^0-9+]/', '', $phone);

    // Load SMS settings from $_ENV
    $apiKey = $_ENV['SMS_API_KEY'] ?? '';
    $senderName = $_ENV['SMS_SENDER_NAME'] ?? 'TESP_HR';
    $simulationMode = $_ENV['SMS_SIMULATION_MODE'] ?? true;

    if ($simulationMode) {
        error_log("[SMS SIMULATION] To: " . substr($phone, -4) . " | Length: " . strlen($message));
        return true;
    }

    if (empty($apiKey)) {
        error_log("[SMS ERROR] Semaphore API Key is not configured in config.php.");
        return false;
    }

    $ch = curl_init();
    $parameters = array(
        'apikey' => $apiKey,
        'number' => $phone,
        'message' => $message,
        'sendername' => $senderName
    );
    curl_setopt($ch, CURLOPT_URL, 'https://api.semaphore.co/api/v4/messages');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($parameters));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $output = curl_exec($ch);
    curl_close($ch);

    if ($output === false) {
        error_log("[SMS ERROR] Semaphore API call failed: " . curl_error($ch));
        return false;
    }

    $response = json_decode($output, true);
    if (isset($response['status']) && $response['status'] === 'success') {
        return true;
    } else {
        error_log("[SMS ERROR] Semaphore API returned error: " . $output);
        return false;
    }
}

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
$filterSearch = $_GET['search'] ?? '';

// [FIX] Preserve filters on redirect
$redirectParams = [];
if ($filterStatus) $redirectParams['status'] = $filterStatus;
if ($filterMonth) $redirectParams['month'] = $filterMonth;
if ($filterWeek) $redirectParams['week'] = $filterWeek;
if ($filterSearch) $redirectParams['search'] = $filterSearch;
$redirectUrl = "recruitment.php" . (!empty($redirectParams) ? "?" . http_build_query($redirectParams) : "");

try {
    // --- HANDLE ADDING CANDIDATE ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_candidate'])) {
        $security->checkCSRF($_POST['csrf_token']);
        $first = ucwords(strtolower(trim($_POST['first_name'] ?? '')));
        $last  = ucwords(strtolower(trim($_POST['last_name'] ?? '')));
        $pos   = ucwords(strtolower(trim($_POST['position_applied'] ?? '')));
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone_number']);
        $date = $_POST['application_date'];

        // [SECURITY] Validation
        if (empty($first) || empty($last) || empty($pos)) $error = "Name and Position are required.";
        elseif (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $error = "Valid application date is required.";
        elseif (mb_strlen($first) > 50 || mb_strlen($last) > 50) $error = "Name is too long (Max 50 chars).";
        elseif (mb_strlen($pos) > 100) $error = "Position is too long (Max 100 chars).";
        elseif (mb_strlen($email) > 100) $error = "Email is too long (Max 100 chars).";
        elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = "Invalid email format.";
        elseif (mb_strlen($phone) > 25 || ($phone && !preg_match("/^[0-9+\-\s()\/]+$/", $phone))) $error = "Invalid phone number format.";
        elseif (!preg_match("/^[a-zA-Z\s\-\.\']+$/", $first)) $error = "First Name contains invalid characters.";
        elseif (!preg_match("/^[a-zA-Z\s\-\.\']+$/", $last)) $error = "Last Name contains invalid characters.";
        elseif (!preg_match("/^[a-zA-Z0-9\s\-\.\,\(\)\/\&']+$/", $pos)) $error = "Position contains invalid characters.";

        if (empty($error) && $first && $last && $pos && $date) {
            $stmt = $pdo->prepare("INSERT INTO candidates (first_name, last_name, position_applied, email, phone_number, application_date, last_follow_up) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$first, $last, $pos, $email, $phone, $date, $date]);

            // [NEW] Automated Initial SMS Greeting
            if (!empty($phone)) {
                $msgText = "Hi $first, we have successfully received your application for $pos at TES Philippines. Our HR team is reviewing your profile and will keep you updated. Thank you!";
                sendSMS($phone, $msgText);
            }

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
        $first = trim($_POST['first_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $pos = trim($_POST['position_applied'] ?? '');
        $status = $_POST['status'];
        $notes = trim($_POST['notes']);
        $reject_reason = trim($_POST['rejection_reason'] ?? '');
        $is_blacklisted = isset($_POST['is_blacklisted']) ? 1 : 0;

        $allowedStatuses = ['New Applicant', 'Screening', 'Interviewed', 'Hired', 'Rejected'];

        // [SECURITY] Strict Validation
        if (empty($first) || empty($last) || empty($pos)) {
            $error = "Name and Position are required.";
        } elseif (mb_strlen($first) > 50 || mb_strlen($last) > 50) {
            $error = "Name is too long (Max 50 chars).";
        } elseif (!preg_match("/^[a-zA-Z\s\-\.\']+$/", $first) || !preg_match("/^[a-zA-Z\s\-\.\']+$/", $last)) {
            $error = "Name contains invalid characters.";
        } elseif (mb_strlen($phone) > 25 || ($phone && !preg_match("/^[0-9+\-\s()\/]+$/", $phone))) {
            $error = "Invalid phone number format.";
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format.";
        } elseif (mb_strlen($pos) > 100 || !preg_match("/^[a-zA-Z0-9\s\-\.\,\(\)\/\&']+$/", $pos)) {
            $error = "Position contains invalid characters.";
        } elseif (!in_array($status, $allowedStatuses, true)) {
            $error = "Invalid status value.";
        } elseif (mb_strlen($reject_reason) > 255) {
            $error = "Rejection reason is too long (Max 255 chars).";
        } elseif (mb_strlen($notes) > 1000) {
            $error = "Notes are too long (Max 1000 chars).";
        } else {
            $stmt = $pdo->prepare("UPDATE candidates SET first_name = ?, last_name = ?, email = ?, phone_number = ?, position_applied = ?, status = ?, notes = ?, rejection_reason = ?, is_blacklisted = ?, last_follow_up = NOW() WHERE id = ?");
            $stmt->execute([$first, $last, $email, $phone, $pos, $status, $notes, $reject_reason, $is_blacklisted, $id]);

            $_SESSION['msg'] = "✅ Candidate status updated.";
            header("Location: " . $redirectUrl . "#cand-" . $id);
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

    // --- HANDLE BLACKLIST CANDIDATE ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['blacklist_candidate'])) {
        $security->checkCSRF($_POST['csrf_token']);
        $id = (int)($_POST['candidate_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($id <= 0) {
            $error = "Invalid candidate selected.";
        } elseif (strlen($reason) > 255) {
            $error = "Reason is too long (Max 255 chars).";
        } else {
            $stmt = $pdo->prepare("UPDATE candidates SET is_blacklisted = 1, status = 'Rejected', rejection_reason = ?, last_follow_up = NOW() WHERE id = ?");
            $stmt->execute([$reason, $id]);
            $_SESSION['msg'] = "✅ Candidate has been blacklisted.";
            header("Location: " . $redirectUrl);
            exit;
        }
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

        if ($cand && !empty($cand['phone_number'])) {
            $phone = $cand['phone_number'];

            // [NEW] System Notification
            try {
                $notifMsg = "Interview scheduled with " . $cand['first_name'] . " " . $cand['last_name'] . " for " . date('M d, Y h:i A', strtotime($intDate));
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, 'Interview Scheduled', ?, 'info')")->execute([$_SESSION['user_id'], $notifMsg]);
            } catch (PDOException $e) {
                // Notification table may not exist; log but don't fail the operation
                error_log("Failed to create notification: " . $e->getMessage());
            }

            // [MHI POLICY] Automated SMS disabled. Log the action manually.
            $pdo->prepare("UPDATE candidates SET status = 'Interviewed', interview_date = ?, last_follow_up = NOW() WHERE id = ?")->execute([$intDate, $id]);
            $_SESSION['msg'] = "✅ Interview scheduled. (Please send the message manually)";
        } else {
            // Allow scheduling even if no phone number
            $pdo->prepare("UPDATE candidates SET status = 'Interviewed', interview_date = ?, last_follow_up = NOW() WHERE id = ?")->execute([$intDate, $id]);
            $_SESSION['msg'] = "✅ Interview scheduled. (No phone number recorded)";
        }
        header("Location: " . $redirectUrl . "#cand-" . $id);
        exit;
    }

    // --- HANDLE FOLLOW UP (SMS) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_followup'])) {
        $security->checkCSRF($_POST['csrf_token']);
        $id = (int)$_POST['candidate_id'];
        $message = trim($_POST['message']);

        $stmt = $pdo->prepare("SELECT * FROM candidates WHERE id = ?");
        $stmt->execute([$id]);
        $cand = $stmt->fetch();

        if ($cand && !empty($cand['phone_number'])) {
            $phone = $cand['phone_number'];

            // [MHI POLICY] Automated SMS disabled.
            $pdo->prepare("UPDATE candidates SET last_follow_up = NOW() WHERE id = ?")->execute([$id]);
            $_SESSION['msg'] = "✅ Follow-up logged. (Please send the message manually)";
        } else {
            $_SESSION['error'] = "❌ Candidate has no phone number recorded.";
        }
        header("Location: " . $redirectUrl . "#cand-" . $id);
        exit;
    }

    // --- HANDLE HIRE CANDIDATE ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hire_candidate'])) {
        $security->checkCSRF($_POST['csrf_token']);
        $id = (int)$_POST['candidate_id'];

        // 1. Update Candidate Status to Hired
        $stmt = $pdo->prepare("UPDATE candidates SET status = 'Hired', last_follow_up = NOW() WHERE id = ?");
        $stmt->execute([$id]);

        // 2. Fetch data to prefill Add Employee form (avoid sending PII in URL)
        $stmt = $pdo->prepare("SELECT * FROM candidates WHERE id = ?");
        $stmt->execute([$id]);
        $c = $stmt->fetch();

        if ($c) {
            $_SESSION['prefill_employee'] = [
                'first_name' => $c['first_name'],
                'last_name' => $c['last_name'],
                'job_title' => $c['position_applied'],
                'email' => $c['email'],
                'contact_number' => $c['phone_number'],
                'hire_date' => date('Y-m-d'),
            ];
        }

        $_SESSION['msg'] = "✅ Candidate marked as Hired. Please complete employee details.";
        header("Location: add_employee.php");
        exit;
    }

    // --- FETCH ANALYTICS DATA ---
    // 1. Total Candidates
    $total = $pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
    // 2. Hired Candidates
    $hired = $pdo->query("SELECT COUNT(*) FROM candidates WHERE status = 'Hired'")->fetchColumn();
    // 3. Needs Follow-up (More than 3 days ago, and not Hired/Rejected)
    $needs_action = $pdo->query("SELECT COUNT(*) FROM candidates WHERE last_follow_up < DATE_SUB(CURDATE(), INTERVAL 3 DAY) AND status NOT IN ('Hired', 'Rejected')")->fetchColumn();

    // [NEW] 4. Interviews Today & Tomorrow
    $stmtToday = $pdo->query("SELECT COUNT(*) FROM candidates WHERE DATE(interview_date) = CURDATE() AND status != 'Rejected'");
    $interviewsToday = $stmtToday->fetchColumn();

    $stmtTomorrow = $pdo->query("SELECT COUNT(*) FROM candidates WHERE DATE(interview_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND status != 'Rejected'");
    $interviewsTomorrow = $stmtTomorrow->fetchColumn();

    // [NEW] 5. Calendar Logic
    $calYear = isset($_GET['cal_year']) ? (int)$_GET['cal_year'] : (int)date('Y');
    $calMonth = isset($_GET['cal_month']) ? (int)$_GET['cal_month'] : (int)date('m');
    $calEvents = [];
    $calStmt = $pdo->prepare("SELECT id, first_name, last_name, interview_date, position_applied, status, email, phone_number, notes, rejection_reason, is_blacklisted FROM candidates WHERE YEAR(interview_date) = ? AND MONTH(interview_date) = ? AND status != 'Rejected'");
    $calStmt->execute([$calYear, $calMonth]);
    while ($row = $calStmt->fetch(PDO::FETCH_ASSOC)) {
        $d = (int)date('j', strtotime($row['interview_date']));
        $calEvents[$d][] = $row;
    }

    // [NEW] 6. Fetch Upcoming Alarms (Interviews in the next hour)
    $alarmStmt = $pdo->prepare("SELECT id, first_name, last_name, interview_date FROM candidates WHERE interview_date > NOW() AND interview_date <= DATE_ADD(NOW(), INTERVAL 1 HOUR) AND status != 'Rejected'");
    $alarmStmt->execute();
    $upcomingAlarms = $alarmStmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. Chart Data (Count by Status)
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
    if ($filterSearch) {
        $terms = preg_split('/[\s,]+/', Validator::sanitizeSearch($filterSearch), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($terms as $term) {
            $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR position_applied LIKE ? OR email LIKE ?)";
            $t = "%$term%";
            array_push($params, $t, $t, $t, $t);
        }
    }

    $sql .= " ORDER BY application_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll();

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
} catch (PDOException $e) {
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
            <div class="card shadow-sm text-center" style="max-width: 500px;">
                <div class="card-body p-5">
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
    throw $e;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Recruitment Dashboard</title>
    <link rel="icon" href="assets/tesp-logo.png?v=4" type="image/png">
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

            .print-only-header {
                display: block !important;
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #666;
                padding-bottom: 10px;
            }

            .print-only-header img {
                height: 60px;
                margin-bottom: 10px;
            }

            .print-only-header h2 {
                font-size: 14pt;
                font-weight: bold;
                margin: 0;
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

        .print-only-header {
            display: none;
        }
    </style>
</head>

<body class="bg-body-tertiary">
    <div class="print-only-header">
        <?php if ($logo_src): ?>
            <img src="<?php echo $logo_src; ?>" alt="TESP Logo">
        <?php endif; ?>
        <h2>Recruitment Pipeline Report</h2>
    </div>

    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white"><i class="bi bi-person-lines-fill"></i> Recruitment & ATS</span>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if ($msg): ?>
            <div class="alert alert-success no-print"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger no-print"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="alert alert-info small p-2 no-print">
            <i class="bi bi-info-circle-fill"></i>
            <strong>Print Instructions:</strong> For best results, use your browser's "Print" function (Ctrl+P). In the print dialog, set the layout to <strong>Landscape</strong> and enable "Background graphics" to ensure colors and styles are included.
        </div>

        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card bg-success text-white shadow-sm h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Successfully Hired</h5>
                        <h2 class="display-4 fw-bold"><?php echo $hired; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card bg-primary text-white shadow-sm h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Interviews Today</h5>
                        <h2 class="display-4 fw-bold"><?php echo $interviewsToday; ?></h2>
                        <small>Tomorrow: <?php echo $interviewsTomorrow; ?> scheduled</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card bg-danger text-white shadow-sm h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Action Required (Follow-up)</h5>
                        <h2 class="display-4 fw-bold"><?php echo $needs_action; ?></h2>
                        <small>No contact in 3+ days</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card bg-dark text-white shadow-sm h-100">
                    <div class="card-body text-center">
                        <h5 class="card-title">Total Database</h5>
                        <h2 class="display-4 fw-bold"><?php echo $total; ?></h2>
                        <small>Active & Past Applicants</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <datalist id="cand_search_list">
                <?php
                $allCands = $pdo->query("SELECT DISTINCT first_name, last_name, position_applied FROM candidates ORDER BY last_name LIMIT 100")->fetchAll();
                foreach ($allCands as $ac) {
                    echo "<option value=\"" . htmlspecialchars($ac['first_name'] . ' ' . $ac['last_name']) . "\">";
                    echo "<option value=\"" . htmlspecialchars($ac['position_applied']) . "\">";
                }
                ?> </datalist>
            <button class="btn btn-light text-primary" type="submit"><i class="bi bi-search"></i></button>
        </div>
        <?php if ($filterStatus || $filterMonth || $filterWeek || $filterSearch): ?>
            <a href="recruitment.php" class="btn btn-sm btn-outline-light text-nowrap" title="Clear Filters"><i class="bi bi-x-lg"></i></a>
        <?php endif; ?>
        </form>

        <div class="no-print d-flex gap-2 ms-lg-3">
            <a href="export_recruitment.php" class="btn btn-sm btn-light text-success fw-bold"><i class="bi bi-file-earmark-excel-fill"></i> Excel</a>
            <a href="print_recruitment.php?status=<?php echo urlencode($filterStatus); ?>&month=<?php echo urlencode($filterMonth); ?>&week=<?php echo urlencode($filterWeek); ?>" target="_blank" class="btn btn-sm btn-light text-dark fw-bold"><i class="bi bi-printer-fill"></i> Print</a>
            <button class="btn btn-sm btn-warning text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-circle-fill"></i> Add Candidate</button>
        </div>
    </div>
    </div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
            <thead class="table-light text-secondary">
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
                    $follow_up_date = strtotime($c['last_follow_up'] ?? $c['application_date']);
                    $days_ago = round((time() - $follow_up_date) / (60 * 60 * 24));

                    // Highlight logic
                    $row_class = "";
                    $badge = match ($c['status']) {
                        'New Applicant' => 'bg-primary rounded-pill',
                        'Screening' => 'bg-info text-dark rounded-pill',
                        'Interviewed' => 'bg-warning text-dark rounded-pill',
                        'Hired' => 'bg-success rounded-pill',
                        'Rejected' => 'bg-danger rounded-pill',
                        default => 'bg-secondary rounded-pill'
                    };

                    if ($days_ago > 3 && !in_array($c['status'], ['Hired', 'Rejected'])) {
                        $row_class = "table-warning border-warning"; // Soft warning!
                    }
                    $isBlacklisted = !empty($c['is_blacklisted']);
                ?>
                    <tr class="<?php echo $row_class; ?>" id="cand-<?php echo $c['id']; ?>">
                        <td class="fw-bold text-primary"><?php echo htmlspecialchars($c['last_name'] . ', ' . $c['first_name']); ?></td>
                        <td>
                            <div class="text-dark fw-semibold text-wrap" style="max-width: 250px;" title="<?php echo htmlspecialchars($c['position_applied']); ?>"><?php echo htmlspecialchars($c['position_applied']); ?></div>
                            <div class="small text-muted border-top mt-1 pt-1"><i class="bi bi-calendar-plus"></i> Applied: <?php echo date('M d, Y', strtotime($c['application_date'])); ?></div>
                        </td>
                        <td>
                            <?php if (!empty($c['phone_number'])): ?><div class="small text-nowrap"><i class="bi bi-telephone-fill text-secondary me-1"></i> <?php echo htmlspecialchars($c['phone_number']); ?></div><?php endif; ?>
                            <?php if (!empty($c['email'])): ?><div class="small text-nowrap"><i class="bi bi-envelope-fill text-secondary me-1"></i> <a href="mailto:<?php echo htmlspecialchars($c['email']); ?>" class="text-decoration-none text-muted"><?php echo htmlspecialchars($c['email']); ?></a></div><?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($c['status']); ?></span>
                            <?php if ($isBlacklisted): ?>
                                <span class="badge bg-dark text-danger border border-danger mt-1"><i class="bi bi-slash-circle"></i> BLACKLISTED</span>
                            <?php endif; ?>
                            <?php if ($c['status'] == 'Rejected' && !empty($c['rejection_reason'])): ?>
                                <div class="small text-danger mt-1">Reason: <?php echo htmlspecialchars($c['rejection_reason']); ?></div>
                            <?php endif; ?>

                            <!-- COLOR CODED INTERVIEW DATE INDICATOR -->
                            <?php if (!empty($c['interview_date'])): ?>
                                <?php
                                $iDate = strtotime($c['interview_date']);
                                $today = strtotime('today');
                                $iDay = strtotime('midnight', $iDate);

                                if ($iDay == $today) {
                                    echo '<div class="small text-danger fw-bold mt-2 bg-danger-subtle px-2 py-1 rounded d-inline-block border border-danger shadow-sm"><i class="bi bi-calendar-event-fill"></i> Today at ' . date('h:i A', $iDate) . '</div>';
                                } elseif ($iDay < $today) {
                                    echo '<div class="small text-muted mt-2"><i class="bi bi-calendar-check"></i> Past: ' . date('M d', $iDate) . '</div>';
                                } else {
                                    echo '<div class="small text-primary mt-2 fw-bold"><i class="bi bi-calendar-event"></i> Upcoming: ' . date('M d, h:i A', $iDate) . '</div>';
                                }
                                ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <?php if ($isBlacklisted): ?>
                                    <button class="btn btn-secondary disabled" title="Cannot Hire: Candidate is Blacklisted" disabled><i class="bi bi-person-x-fill"></i></button>
                                <?php else: ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Mark as Hired and proceed to Add Employee?');">
                                        <input type="hidden" name="hire_candidate" value="1">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="candidate_id" value="<?php echo $c['id']; ?>">
                                        <button type="submit" class="btn btn-outline-success" title="Hire & Add to Employee Database">
                                            <i class="bi bi-person-check-fill"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <!-- [FIX] Bulletproof JSON injection for all Action Buttons -->
                                <?php $safeData = htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8'); ?>
                                <button type="button" class="btn btn-outline-primary" title="Schedule Interview" onclick='openSchedModal(<?php echo $safeData; ?>)'><i class="bi bi-calendar-event"></i></button>
                                <button type="button" class="btn btn-outline-info" title="Send SMS Follow Up" onclick='openFollowModal(<?php echo $safeData; ?>)'><i class="bi bi-chat-left-text-fill"></i></button>
                                <button type="button" class="btn btn-outline-secondary" title="Edit Profile" onclick='openEditModal(<?php echo $safeData; ?>)'><i class="bi bi-pencil-square"></i></button>
                                <button type="button" class="btn btn-outline-danger" title="Blacklist Candidate" onclick="openBlacklistModal(<?php echo $c['id']; ?>)"><i class="bi bi-slash-circle"></i></button>
                                <button type="button" class="btn btn-outline-danger" title="Delete" onclick="deleteCandidate(<?php echo $c['id']; ?>)"><i class="bi bi-trash"></i></button>
                            </div>
                        </td>
                        <td class="small">
                            <?php echo date('M d, Y', $follow_up_date); ?>
                            <?php if ($row_class == "table-warning border-warning"): ?>
                                <br><span class="badge bg-warning text-dark mt-1"><i class="bi bi-clock-history"></i> <?php echo $days_ago; ?> days ago</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($candidates)): ?>
                    <tr>
                        <td colspan="6" class="text-center p-4 text-muted">No candidates found matching your criteria.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    </div>
    </div>

    <!-- PIPELINE BREAKDOWN (MOVED DOWN) -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow border-0 h-100">
            <div class="card-header bg-dark text-white fw-bold"><i class="bi bi-pie-chart-fill me-2"></i> Pipeline Breakdown</div>
            <div class="card-body" style="min-height: 300px;">
                <canvas id="pipelineChart"></canvas>
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
                        <input type="text" name="position_applied" class="form-control" list="job_list" placeholder="Select or Type Position..." required maxlength="100" pattern="[a-zA-Z0-9\s\-\.\,\(\)\/\&']+" title="Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)\/\&']/g, '')">
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

    <div class="modal fade" id="BlacklistModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Blacklist Candidate</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="blacklist_candidate" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="candidate_id" id="blacklist_id">

                    Are you sure you want to Blacklist this candidate?
                    This will prevent them from being hired
                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <textarea name="reason" class="form-control" rows="3" maxlength="255"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Blacklist</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT MODAL -->
    <div class="modal fade" id="editModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-info text-dark">
                    <h5 class="modal-title"><i class="bi bi-person-lines-fill"></i> Edit Candidate Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="edit_candidate" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="candidate_id" id="edit_id">

                    <div class="row">
                        <div class="col-md-6 border-end">
                            <h6 class="text-primary fw-bold mb-3 border-bottom pb-2">Personal Info</h6>
                            <div class="row g-2 mb-2">
                                <div class="col"><label class="form-label small fw-bold">First Name</label><input type="text" name="first_name" id="edit_first" class="form-control form-control-sm" required maxlength="50" pattern="[a-zA-Z\s\-\.\']+" title="Letters, spaces, dots, dashes, apostrophes" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\']/g, '')"></div>
                                <div class="col"><label class="form-label small fw-bold">Last Name</label><input type="text" name="last_name" id="edit_last" class="form-control form-control-sm" required maxlength="50" pattern="[a-zA-Z\s\-\.\']+" title="Letters, spaces, dots, dashes, apostrophes" oninput="this.value = this.value.replace(/[^a-zA-Z\s\-\.\']/g, '')"></div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-bold">Phone Number</label>
                                <input type="text" name="phone_number" id="edit_phone" class="form-control form-control-sm" maxlength="25" pattern="[0-9+\-\s()\/]+" oninput="this.value = this.value.replace(/[^0-9+\-\s()\/]/g, '')">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-bold">Email</label>
                                <input type="email" name="email" id="edit_email" class="form-control form-control-sm" maxlength="100">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Position Applied For</label>
                                <input type="text" name="position_applied" id="edit_pos" class="form-control form-control-sm" required maxlength="100" pattern="[a-zA-Z0-9\s\-\.\,\(\)\/\&']+" title="Alphanumeric and basic punctuation" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)\/\&']/g, '')">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary fw-bold mb-3 border-bottom pb-2">Application Status</h6>
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Current Phase</label>
                                <select name="status" id="edit_status" class="form-select form-select-sm border-info fw-bold" onchange="toggleRejectionField()">
                                    <option value="New Applicant">New Applicant</option>
                                    <option value="Screening">Screening</option>
                                    <option value="Interviewed">Interviewed</option>
                                    <option value="Hired">Hired</option>
                                    <option value="Rejected">Rejected</option>
                                </select>
                            </div>
                            <div class="mb-3" id="reject_div" style="display:none;">
                                <label class="form-label text-danger small fw-bold">Reason for Rejection</label>
                                <input type="text" name="rejection_reason" id="edit_reject_reason" class="form-control form-control-sm border-danger" placeholder="e.g. Failed technical exam" maxlength="255">
                            </div>
                            <div class="form-check mb-3 p-2 border rounded bg-light">
                                <input class="form-check-input ms-1" type="checkbox" name="is_blacklisted" id="edit_blacklist" value="1">
                                <label class="form-check-label fw-bold text-danger ms-2 small" for="edit_blacklist"><i class="bi bi-slash-circle"></i> Blacklist (Do Not Hire)</label>
                            </div>
                            <div class="mb-0">
                                <label class="form-label small fw-bold">Notes / Remarks</label>
                                <textarea name="notes" id="edit_notes" class="form-control form-control-sm" rows="4" maxlength="1000" placeholder="Add interview notes..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-info fw-bold">Save Changes</button></div>
            </form>
        </div>
    </div>

    <!-- SCHEDULE MODAL -->
    <div class="modal fade" id="scheduleModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="schedModalTitle"><i class="bi bi-calendar-check"></i> Schedule Interview</h5>
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
                        <label class="form-label text-success fw-bold"><i class="bi bi-chat-left-text"></i> Message Template</label>
                        <div class="input-group">
                            <textarea name="message" id="sched_msg" class="form-control" rows="5" required></textarea>
                            <button type="button" class="btn btn-outline-success" onclick="copyRecruitText('sched_msg')"><i class="bi bi-clipboard"></i> Copy</button>
                        </div>
                    </div>
                    <div class="alert alert-warning small"><i class="bi bi-info-circle-fill"></i> Automated SMS is disabled. Copy the text and send it manually.</div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Mark as Scheduled</button>
                </div>
            </form>
        </div>
    </div>

    <!-- FOLLOW UP MODAL -->
    <div class="modal fade" id="followUpModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-info text-dark">
                    <h5 class="modal-title"><i class="bi bi-chat-dots-fill"></i> Send SMS Follow Up</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="send_followup" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="candidate_id" id="follow_id">

                    <div class="mb-3">
                        <label class="form-label text-success fw-bold"><i class="bi bi-chat-left-text"></i> Message Template</label>
                        <div class="input-group">
                            <textarea name="message" id="follow_msg" class="form-control" rows="4" required></textarea>
                            <button type="button" class="btn btn-outline-success" onclick="copyRecruitText('follow_msg')"><i class="bi bi-clipboard"></i> Copy</button>
                        </div>
                    </div>
                    <div class="alert alert-warning small mb-0"><i class="bi bi-info-circle-fill"></i> Automated SMS is disabled. Copy the text and send it manually.</div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-info fw-bold">Log Follow Up</button>
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
        let pipelineChart = null;
        if (ctx) {
            pipelineChart = new Chart(ctx, {
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

            // [NEW] Dark Mode Adapter for Chart
            function updateChartTheme() {
                const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
                const textColor = isDark ? '#adb5bd' : '#6c757d';

                if (pipelineChart && pipelineChart.options.plugins && pipelineChart.options.plugins.legend) {
                    pipelineChart.options.plugins.legend.labels = pipelineChart.options.plugins.legend.labels || {};
                    pipelineChart.options.plugins.legend.labels.color = textColor;
                }
                if (pipelineChart) pipelineChart.update();
            }

            new MutationObserver(updateChartTheme).observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['data-bs-theme']
            });
            updateChartTheme(); // Initial check
        }

        // [FIX] Auto-Capitalize Fields matching add_employee.php logic
        document.addEventListener("DOMContentLoaded", () => {
            const fieldsToCap = ['first_name', 'last_name', 'position_applied'];
            fieldsToCap.forEach(name => {
                document.querySelectorAll(`input[name="${name}"]`).forEach(input => {
                    input.addEventListener('input', function() {
                        let words = this.value.split(' ');
                        for (let i = 0; i < words.length; i++) {
                            if (words[i].length > 0) words[i] = words[i].charAt(0).toUpperCase() + words[i].slice(1).toLowerCase();
                        }
                        this.value = words.join(' ');
                    });
                });
            });
        });

        // --- ACTION BUTTON LOGIC ---
        function openEditModal(data) {
            document.getElementById('edit_id').value = data.id || '';
            document.getElementById('edit_first').value = data.first_name || '';
            document.getElementById('edit_last').value = data.last_name || '';
            document.getElementById('edit_email').value = data.email || '';
            document.getElementById('edit_phone').value = data.phone_number || '';
            document.getElementById('edit_pos').value = data.position_applied || '';
            document.getElementById('edit_status').value = data.status || 'New Applicant';
            document.getElementById('edit_notes').value = data.notes || '';
            document.getElementById('edit_blacklist').checked = (data.is_blacklisted == 1);

            const rejectInput = document.getElementById('edit_reject_reason');
            if (rejectInput) rejectInput.value = data.rejection_reason || '';

            toggleRejectionField();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
        }

        function openSchedModal(data) {
            // Allow scheduling even without a phone number; SMS availability may vary.
            // Note: Backend allows setting interview_date regardless of phone.
            document.getElementById('sched_id').value = data.id;

            const titleEl = document.getElementById('schedModalTitle');
            if (titleEl) titleEl.innerHTML = `<i class="bi bi-calendar-check"></i> ${data.interview_date ? 'Reschedule' : 'Schedule'} Interview`;

            const dateInput = document.getElementById('sched_date');
            if (dateInput) dateInput.value = data.interview_date ? data.interview_date.replace(' ', 'T') : '';

            document.getElementById('sched_msg').value = `Dear ${data.first_name},\n\nWe are pleased to invite you for an interview regarding your application at TES Philippines, Inc.\n\nPlease reply to this message to confirm your availability.\n\nBest Regards,\nHR Department`;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('scheduleModal')).show();
        }

        function openFollowModal(data) {
            if (!data.phone_number) return alert("No phone number recorded.");
            document.getElementById('follow_id').value = data.id;
            document.getElementById('follow_msg').value = `Dear ${data.first_name},\n\nGreetings from TES Philippines, Inc.\n\nWe are following up regarding your recent job application. Please contact our HR Department at your earliest convenience for updates.\n\nBest Regards,\nHR Department`;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('followUpModal')).show();
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
                // [FIX] Corrected the ID to match the HTML form
                document.getElementById('del_id').value = id;
                document.getElementById('deleteForm').submit();
            }
        }

        function openBlacklistModal(id) {
            const input = document.getElementById('blacklist_id');
            if (input) {
                input.value = id;
            }
            bootstrap.Modal.getOrCreateInstance(document.getElementById('BlacklistModal')).show();
        }

        function copyRecruitText(elementId) {
            const copyText = document.getElementById(elementId);
            if (!copyText) return;
            copyText.select();
            copyText.setSelectionRange(0, 99999);

            navigator.clipboard.writeText(copyText.value.trim())
                .then(() => {
                    if (window.Swal && typeof Swal.fire === 'function') {
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: 'Message copied to clipboard!',
                            showConfirmButton: false,
                            timer: 2000
                        });
                    } else {
                        alert('Message copied to clipboard!');
                    }
                })
                .catch((error) => {
                    console.error('Clipboard copy failed:', error);
                    if (window.Swal && typeof Swal.fire === 'function') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Copy failed',
                            text: 'Unable to copy message to clipboard. Please copy manually.'
                        });
                    } else {
                        alert('Unable to copy message to clipboard. Please copy manually.');
                    }
                });
        }
    </script>
    <script src="assets/sweetalert2.all.min.js"></script>
    <script src="dark_mode.js"></script>
</body>

</html>