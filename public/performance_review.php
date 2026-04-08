<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/Validator.php';
require '../src/SearchHelper.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// 1. SECURITY: Admin, Manager, HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    header("Location: index.php");
    exit;
}

// [AUTO-REPAIR] Create table if it doesn't exist
try {
    $pdo->query("SELECT 1 FROM hr_performance_reviews LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("CREATE TABLE hr_performance_reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        reviewer_id INT NOT NULL,
        review_date DATE NOT NULL,
        rating INT NOT NULL,
        strengths TEXT,
        weaknesses TEXT,
        goals TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_emp_id (employee_id),
        KEY idx_reviewer_id (reviewer_id),
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB;");
}

// [AUTO-REPAIR] Check for reviewer_id column and foreign key
try {
    $chkCol = $pdo->query("SHOW COLUMNS FROM hr_performance_reviews LIKE 'reviewer_id'");
    if ($chkCol->rowCount() == 0) {
        $pdo->exec("ALTER TABLE hr_performance_reviews ADD COLUMN reviewer_id INT NOT NULL AFTER employee_id");
        $pdo->exec("ALTER TABLE hr_performance_reviews ADD CONSTRAINT fk_perf_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE");
    }
} catch (PDOException $e) {
}

// [AUTO-REPAIR] Check for review_date column
try {
    $chkCol = $pdo->query("SHOW COLUMNS FROM hr_performance_reviews LIKE 'review_date'");
    if ($chkCol->rowCount() == 0) {
        $pdo->exec("ALTER TABLE hr_performance_reviews ADD COLUMN review_date DATE NOT NULL DEFAULT CURRENT_DATE AFTER reviewer_id");
    }
} catch (PDOException $e) {
}

// [AUTO-REPAIR] Check for rating column
try {
    $chkCol = $pdo->query("SHOW COLUMNS FROM hr_performance_reviews LIKE 'rating'");
    if ($chkCol->rowCount() == 0) {
        $pdo->exec("ALTER TABLE hr_performance_reviews ADD COLUMN rating INT NOT NULL DEFAULT 3 AFTER review_date");
    }
} catch (PDOException $e) {
}

// [AUTO-REPAIR] Add missing text fields for new review system
try {
    $chkStrengths = $pdo->query("SHOW COLUMNS FROM hr_performance_reviews LIKE 'strengths'");
    if ($chkStrengths->rowCount() == 0) {
        $pdo->exec("ALTER TABLE hr_performance_reviews ADD COLUMN strengths TEXT NULL AFTER rating");
    }

    $chkWeaknesses = $pdo->query("SHOW COLUMNS FROM hr_performance_reviews LIKE 'weaknesses'");
    if ($chkWeaknesses->rowCount() == 0) {
        $pdo->exec("ALTER TABLE hr_performance_reviews ADD COLUMN weaknesses TEXT NULL AFTER strengths");
    }

    $chkGoals = $pdo->query("SHOW COLUMNS FROM hr_performance_reviews LIKE 'goals'");
    if ($chkGoals->rowCount() == 0) {
        $pdo->exec("ALTER TABLE hr_performance_reviews ADD COLUMN goals TEXT NULL AFTER weaknesses");
    }
} catch (PDOException $e) {
}

// [AUTO-REPAIR] Add custom_reviewer column
try {
    $chkCol = $pdo->query("SHOW COLUMNS FROM hr_performance_reviews LIKE 'custom_reviewer'");
    if ($chkCol->rowCount() == 0) {
        $pdo->exec("ALTER TABLE hr_performance_reviews ADD COLUMN custom_reviewer VARCHAR(100) NULL AFTER reviewer_id");
    }
} catch (PDOException $e) {
}

$security = new Security($pdo);
$logger = new Logger($pdo);
$csrf_token = $security->generateCSRF();
$msg = "";

// [HELPER] Preserve Filters for Redirects
$keepParams = array_intersect_key($_GET, array_flip(['search', 'filter_dept', 'filter_rating', 'filter_year']));

// [NEW] HANDLE DELETE REVIEW
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_review'])) {
    $security->checkCSRF($_POST['csrf_token']);
    $del_id = (int)$_POST['review_id'];
    $pdo->prepare("DELETE FROM hr_performance_reviews WHERE id = ?")->execute([$del_id]);
    $logger->log($_SESSION['user_id'], 'DELETE_REVIEW', "Deleted performance review ID: $del_id");

    $keepParams['msg'] = "🗑️ Review deleted successfully.";
    header("Location: performance_review.php?" . http_build_query($keepParams));
    exit;
}

// [NEW] HANDLE EDIT REVIEW
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_review'])) {
    if (!$security->checkRateLimit($_SERVER['REMOTE_ADDR'])) {
        die("Too many requests.");
    }
    try {
        $security->checkCSRF($_POST['csrf_token']);

        $edit_id = (int)$_POST['review_id'];
        $date = $_POST['review_date'];
        $rating = (int)$_POST['rating'];
        $strengths = trim($_POST['strengths']);
        $weaknesses = trim($_POST['weaknesses']);
        $goals = trim($_POST['goals']);
        $custom_reviewer = trim($_POST['reviewer_name']);

        // [NEW] Validation and Character Limits
        if ($rating < 1 || $rating > 5) {
            $msg = "❌ Invalid rating selected.";
        } elseif (strlen($strengths) > 5000) {
            $msg = "❌ 'Strengths' field is too long (Max 5000 chars).";
        } elseif (strlen($weaknesses) > 5000) {
            $msg = "❌ 'Areas for Improvement' field is too long (Max 5000 chars).";
        } elseif (strlen($goals) > 5000) {
            $msg = "❌ 'Goals' field is too long (Max 5000 chars).";
        } elseif (strlen($custom_reviewer) > 100) {
            $msg = "❌ Reviewer Name is too long (Max 100 chars).";
        }

        if ($msg) {
            goto end_of_post;
        }

        if ($edit_id && $date && $rating >= 1 && $rating <= 5) {
            $stmt = $pdo->prepare("UPDATE hr_performance_reviews SET review_date = ?, rating = ?, strengths = ?, weaknesses = ?, goals = ?, custom_reviewer = ? WHERE id = ?");
            $stmt->execute([$date, $rating, $strengths, $weaknesses, $goals, $custom_reviewer, $edit_id]);

            $logger->log($_SESSION['user_id'], 'EDIT_REVIEW', "Updated performance review ID: $edit_id");

            $keepParams['msg'] = "✅ Performance review updated successfully.";
            header("Location: performance_review.php?" . http_build_query($keepParams));
            exit;
        } else {
            $msg = "❌ Please fill all required fields.";
        }
    } catch (Exception $e) {
        $msg = "Error: " . $e->getMessage();
    }
}

// 2. HANDLE ADD REVIEW
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_review'])) {
    if (!$security->checkRateLimit($_SERVER['REMOTE_ADDR'])) {
        die("Too many requests.");
    }
    try {
        $security->checkCSRF($_POST['csrf_token']);

        $emp_id = (int)$_POST['employee_id'];
        $date = $_POST['review_date'];
        $rating = (int)$_POST['rating'];
        $strengths = trim($_POST['strengths']);
        $weaknesses = trim($_POST['weaknesses']);
        $goals = trim($_POST['goals']);
        $custom_reviewer = trim($_POST['reviewer_name']);

        // [NEW] Validation and Character Limits
        if ($rating < 1 || $rating > 5) {
            $msg = "❌ Invalid rating selected.";
        } elseif (strlen($strengths) > 5000) {
            $msg = "❌ 'Strengths' field is too long (Max 5000 chars).";
        } elseif (strlen($weaknesses) > 5000) {
            $msg = "❌ 'Areas for Improvement' field is too long (Max 5000 chars).";
        } elseif (strlen($goals) > 5000) {
            $msg = "❌ 'Goals' field is too long (Max 5000 chars).";
        } elseif (strlen($custom_reviewer) > 100) {
            $msg = "❌ Reviewer Name is too long (Max 100 chars).";
        }

        if ($msg) {
            goto end_of_post;
        }

        if ($emp_id && $date && $rating >= 1 && $rating <= 5) {
            $stmt = $pdo->prepare("INSERT INTO hr_performance_reviews (employee_id, reviewer_id, custom_reviewer, review_date, rating, strengths, weaknesses, goals) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$emp_id, $_SESSION['user_id'], $custom_reviewer, $date, $rating, $strengths, $weaknesses, $goals]);

            $logger->log($_SESSION['user_id'], 'ADD_REVIEW', "Added performance review for employee ID: $emp_id");

            $keepParams['msg'] = "✅ Performance review added successfully.";
            header("Location: performance_review.php?" . http_build_query($keepParams));
            exit;
        } else {
            $msg = "❌ Please fill all required fields.";
        }
    } catch (Exception $e) {
        $msg = "Error: " . $e->getMessage();
    }
}

end_of_post:

// 3. FETCH FILTER OPTIONS & DATA
$depts = $pdo->query("SELECT DISTINCT dept FROM employees WHERE dept != '' ORDER BY dept ASC")->fetchAll(PDO::FETCH_COLUMN);
$years = $pdo->query("SELECT DISTINCT YEAR(review_date) FROM hr_performance_reviews ORDER BY 1 DESC")->fetchAll(PDO::FETCH_COLUMN);

// [SECURITY] Input Validation & Sanitization
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if (mb_strlen($search) > 50) $search = mb_substr($search, 0, 50); // Enforce char limit (Safe for names)
$search = preg_replace('/[^a-zA-Z0-9\s\-\.\,\(\)]/', '', $search); // Whitelist chars (Added Parentheses support for Datalist)

$filter_dept   = isset($_GET['filter_dept']) ? trim($_GET['filter_dept']) : '';
$filter_rating = isset($_GET['filter_rating']) ? (int)$_GET['filter_rating'] : '';
$filter_year   = isset($_GET['filter_year']) ? (int)$_GET['filter_year'] : '';

$conditions = [];
$params = [];

if (!empty($search)) {
    $terms = preg_split('/[\s,]+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($terms as $term) {
        $conditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.emp_id LIKE ?)";
        $t = "%$term%";
        array_push($params, $t, $t, $t);
    }
}
if ($filter_dept) {
    $conditions[] = "e.dept = ?";
    $params[] = $filter_dept;
}
if ($filter_rating) {
    $conditions[] = "p.rating = ?";
    $params[] = $filter_rating;
}
if ($filter_year) {
    $conditions[] = "YEAR(p.review_date) = ?";
    $params[] = $filter_year;
}

$where_sql = $conditions ? "WHERE " . implode(' AND ', $conditions) : "";

// [NEW] EXPORT TO EXCEL HANDLER
if (isset($_GET['export'])) {
    // 1. Headers for Download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Performance_Reviews_' . date('Y-m-d') . '.csv');

    // 2. Open Output Stream
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // BOM for Excel compatibility

    // 3. CSV Column Headers
    fputcsv($output, ['Review Date', 'Employee Name', 'ID', 'Dept', 'Rating', 'Strengths', 'Areas for Improvement', 'Goals', 'Reviewer']);

    // 4. Fetch Data using same filters
    $expStmt = $pdo->prepare("
        SELECT p.*, e.first_name, e.last_name, e.emp_id, e.dept, u.username, u.account_owner
        FROM hr_performance_reviews p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON p.reviewer_id = u.id
        $where_sql
        ORDER BY p.review_date DESC
    ");
    $expStmt->execute($params);

    while ($row = $expStmt->fetch(PDO::FETCH_ASSOC)) {
        // Logic: Custom Reviewer > Account Owner > Username
        $reviewer = !empty($row['custom_reviewer']) ? $row['custom_reviewer'] : (!empty($row['account_owner']) ? $row['account_owner'] : $row['username']);
        fputcsv($output, [
            $row['review_date'],
            $row['last_name'] . ', ' . $row['first_name'],
            $row['emp_id'],
            $row['dept'],
            $row['rating'],
            $row['strengths'],
            $row['weaknesses'],
            $row['goals'],
            $reviewer
        ]);
    }
    fclose($output);
    exit;
}

$reviewsStmt = $pdo->prepare("
    SELECT p.*, e.first_name, e.last_name, e.emp_id, e.dept, u.username, u.account_owner
    FROM hr_performance_reviews p
    JOIN employees e ON p.employee_id = e.id
    JOIN users u ON p.reviewer_id = u.id
    $where_sql
    ORDER BY p.review_date DESC
");
$reviewsStmt->execute($params);
$reviewsArray = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch employees for dropdown
$employees = $pdo->query("SELECT id, first_name, last_name, emp_id FROM employees WHERE status = 'Active' ORDER BY last_name ASC")->fetchAll();

// [NEW] Fetch current user's name preference for default value
$currentUser = [];
if (isset($_SESSION['user_id'])) {
    $uStmt = $pdo->prepare("SELECT username, account_owner FROM users WHERE id = ?");
    $uStmt->execute([$_SESSION['user_id']]);
    $currentUser = $uStmt->fetch();
}

// [NEW] Fuzzy Search Logic (Did you mean?)
$didYouMean = null;
$didYouMeanLink = "#";
if (count($reviewsArray) === 0 && !empty($search)) {
    $closest = SearchHelper::findBestMatch($pdo, $search);
    if ($closest) {
        $didYouMean = $closest;
        $didYouMeanLink = "performance_review.php?search=" . urlencode($closest);
    }
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
    <title>Performance Reviews</title>
    <link rel="icon" href="assets/tesp-logo.png?v=4" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <link rel="icon" type="image/png" href="../uploads/tesp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../uploads/tesp-logo.png">
    <link rel="apple-touch-icon" href="../uploads/tesp-logo.png">
    <style>
        /* =========================================
           PRINT STYLES: Forces table to fit on paper 
           ========================================= */
        @media print {
            @page {
                size: landscape;
                /* Recommend landscape for wide tables */
                margin: 0.5in;
            }

            /* Hide UI elements that shouldn't be printed */
            nav,
            .btn,
            form,
            .modal,
            .alert,
            .no-print {
                display: none !important;
            }

            /* Expand the container to utilize full paper width */
            .container {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            /* Remove card borders/shadows for a cleaner print */
            .card {
                border: none !important;
                box-shadow: none !important;
            }

            /* Disable horizontal scrolling */
            .table-responsive {
                overflow: visible !important;
            }

            /* Force the table to respect strict layout boundaries */
            table {
                width: 100% !important;
                table-layout: fixed;
                /* Crucial: stops columns from expanding past screen */
                border-collapse: collapse !important;
            }

            /* Adjust fonts and force text wrapping */
            th,
            td {
                word-wrap: break-word;
                white-space: normal !important;
                font-size: 10pt;
                /* Smaller font to fit more text */
                padding: 6px !important;
                border: 1px solid #dee2e6 !important;
                /* Ensure borders print */
            }

            /* Hide Action Column in Print */
            th:last-child,
            td:last-child {
                display: none !important;
            }

            /* Assign specific widths to columns to balance the paper */
            th:nth-child(1) {
                width: 10%;
            }

            /* Date */
            th:nth-child(2) {
                width: 18%;
            }

            /* Employee */
            th:nth-child(3) {
                width: 12%;
            }

            /* Rating */
            th:nth-child(4) {
                width: 25%;
            }

            /* Strengths */
            th:nth-child(5) {
                width: 25%;
            }

            /* Weaknesses */
            th:nth-child(6) {
                width: 10%;
            }

            /* Reviewer */

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

            /* Prevent rows from splitting in half across pages */
            tr {
                page-break-inside: avoid;
            }

            /* Ensure star colors print accurately */
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
        <h2>HR Performance Reviews Report</h2>
    </div>
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <div class="d-flex align-items-center gap-2 w-100">
                <a class="navbar-brand" href="index.php">Back to Dashboard</a>
                <span class="navbar-text text-white me-auto"><i class="bi bi-clipboard2-data-fill"></i> Performance Management</span>
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode"><i class="bi bi-moon-stars-fill"></i></button>
            </div>
        </div>
    </nav>

    <div class="container">
        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($_GET['msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <script>
                if (window.history.replaceState) {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('msg');
                    window.history.replaceState(null, null, url.toString());
                }
            </script>
        <?php endif; ?>

        <?php if ($msg): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <form class="d-flex gap-2" style="width: 400px;">
                <input type="text" name="search" class="form-control" placeholder="Search Employee..." value="<?php echo htmlspecialchars($search); ?>" maxlength="50" pattern="[a-zA-Z0-9\s\-\.\,\(\)]+" title="Allowed: Letters, Numbers, Spaces, - . , ( )" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-\.\,\(\)]/g, '')" list="emp_suggestions" autocomplete="off">
                <datalist id="emp_suggestions">
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name'] . ' (' . $emp['emp_id'] . ')'); ?>">
                        <?php endforeach; ?>
                </datalist>
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
                <?php if ($search): ?>
                    <a href="performance_review.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </form>

            <div class="d-flex gap-2">
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="bi bi-printer"></i> Print Report
                </button>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addReviewModal">
                    <i class="bi bi-plus-lg"></i> Add New Review
                </button>
            </div>
        </div>

        <?php if ($didYouMean): ?>
            <div class="alert alert-warning shadow-sm">
                <i class="bi bi-lightbulb-fill me-2"></i> No results found. Did you mean:
                <a href="<?php echo $didYouMeanLink; ?>" class="fw-bold text-dark text-decoration-underline"><?php echo htmlspecialchars($didYouMean); ?></a>?
            </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Review Date</th>
                            <th>Employee</th>
                            <th>Rating</th>
                            <th>Strengths</th>
                            <th>Areas for Improvement</th>
                            <th>Performance Reviewer</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reviewsArray as $row): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($row['review_date'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($row['emp_id']); ?></small>
                                </td>
                                <td>
                                    <?php for ($i = 0; $i < 5; $i++): ?>
                                        <i class="bi <?php echo $i < $row['rating'] ? 'bi-star-fill text-warning' : 'bi-star text-muted'; ?>"></i>
                                    <?php endfor; ?>
                                </td>
                                <td class="small"><?php echo nl2br(htmlspecialchars($row['strengths'])); ?></td>
                                <td class="small"><?php echo nl2br(htmlspecialchars($row['weaknesses'])); ?></td>
                                <td>
                                    <?php
                                    $reviewer = !empty($row['custom_reviewer']) ? $row['custom_reviewer'] : (!empty($row['account_owner']) ? $row['account_owner'] : $row['username']);
                                    ?>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($reviewer); ?></span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick='openEditModal(<?php echo $row['id']; ?>, <?php echo json_encode($row['review_date']); ?>, <?php echo $row['rating']; ?>, <?php echo json_encode($row['strengths'] ?? "", JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($row['weaknesses'] ?? "", JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($row['goals'] ?? "", JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($reviewer, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Edit">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this review?');" class="d-inline">
                                        <input type="hidden" name="delete_review" value="1">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="review_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($reviewsArray) === 0): ?>
                            <tr>
                                <td colspan="7" class="text-center p-4 text-muted">No performance reviews found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addReviewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Add Performance Review</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="add_review" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Employee</label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">-- Select Employee --</option>
                                <?php foreach ($employees as $emp):
                                    $isSelected = ($search === $emp['emp_id'] || strpos($search, '(' . $emp['emp_id'] . ')') !== false) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo $emp['id']; ?>" <?php echo $isSelected; ?>>
                                        <?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name'] . ' (' . $emp['emp_id'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">Review Date</label>
                            <input type="date" name="review_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">Overall Rating</label>
                            <select name="rating" class="form-select" required>
                                <option value="">-- Rate 1-5 --</option>
                                <option value="5">5 - Excellent</option>
                                <option value="4">4 - Exceeds Expectations</option>
                                <option value="3">3 - Meets Expectations</option>
                                <option value="2">2 - Needs Improvement</option>
                                <option value="1">1 - Unsatisfactory</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold">Reviewer Name</label>
                            <input type="text" name="reviewer_name" class="form-control" value="<?php echo htmlspecialchars($currentUser['account_owner'] ?? $currentUser['username'] ?? ''); ?>" maxlength="100" placeholder="Name of person conducting the review">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Strengths / Accomplishments</label>
                        <textarea name="strengths" class="form-control" rows="3" placeholder="e.g., Excellent problem-solving skills, Consistently meets deadlines..." maxlength="5000"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Areas for Improvement</label>
                        <textarea name="weaknesses" class="form-control" rows="3" placeholder="e.g., Can improve on time management for larger projects..." maxlength="5000"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Goals / Action Plan</label>
                        <textarea name="goals" class="form-control" rows="3" placeholder="e.g., Increase project ownership, complete training goals..." maxlength="5000"></textarea>
                    </div>


                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Review</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT REVIEW MODAL -->
    <div class="modal fade" id="editReviewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Performance Review</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="edit_review" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="review_id" id="edit_review_id">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Review Date</label>
                            <input type="date" name="review_date" id="edit_review_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Overall Rating</label>
                            <select name="rating" id="edit_rating" class="form-select" required>
                                <option value="5">5 - Excellent</option>
                                <option value="4">4 - Exceeds Expectations</option>
                                <option value="3">3 - Meets Expectations</option>
                                <option value="2">2 - Needs Improvement</option>
                                <option value="1">1 - Unsatisfactory</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold">Reviewer Name</label>
                            <input type="text" name="reviewer_name" id="edit_reviewer_name" class="form-control" maxlength="100" placeholder="Name of person conducting the review">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Strengths / Accomplishments</label>
                        <textarea name="strengths" id="edit_strengths" class="form-control" rows="3" maxlength="5000"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Areas for Improvement</label>
                        <textarea name="weaknesses" id="edit_weaknesses" class="form-control" rows="3" maxlength="5000"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Goals / Action Plan</label>
                        <textarea name="goals" id="edit_goals" class="form-control" rows="3" maxlength="5000"></textarea>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script>
        function openEditModal(id, date, rating, strengths, weaknesses, goals, reviewer) {
            document.getElementById('edit_review_id').value = id;
            document.getElementById('edit_review_date').value = date;
            document.getElementById('edit_rating').value = rating;
            document.getElementById('edit_strengths').value = strengths;
            document.getElementById('edit_weaknesses').value = weaknesses;
            document.getElementById('edit_goals').value = goals;
            document.getElementById('edit_reviewer_name').value = reviewer;

            new bootstrap.Modal(document.getElementById('editReviewModal')).show();
        }
    </script>
    <script src="dark_mode.js"></script>
</body>

</html>