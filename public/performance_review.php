<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/Validator.php';
require '../src/SearchHelper.php';
session_start();

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

$security = new Security($pdo);
$logger = new Logger($pdo);
$csrf_token = $security->generateCSRF();
$msg = "";

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

        // [NEW] Validation and Character Limits
        if ($rating < 1 || $rating > 5) {
            $msg = "❌ Invalid rating selected.";
        } elseif (strlen($strengths) > 5000) {
            $msg = "❌ 'Strengths' field is too long (Max 5000 chars).";
        } elseif (strlen($weaknesses) > 5000) {
            $msg = "❌ 'Areas for Improvement' field is too long (Max 5000 chars).";
        } elseif (strlen($goals) > 5000) {
            $msg = "❌ 'Goals' field is too long (Max 5000 chars).";
        }

        if ($msg) {
            goto end_of_post;
        }

        if ($emp_id && $date && $rating >= 1 && $rating <= 5) {
            $stmt = $pdo->prepare("INSERT INTO hr_performance_reviews (employee_id, reviewer_id, review_date, rating, strengths, weaknesses, goals) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$emp_id, $_SESSION['user_id'], $date, $rating, $strengths, $weaknesses, $goals]);

            $logger->log($_SESSION['user_id'], 'ADD_REVIEW', "Added performance review for employee ID: $emp_id");
            $msg = "✅ Performance review added successfully.";
        } else {
            $msg = "❌ Please fill all required fields.";
        }
    } catch (Exception $e) {
        $msg = "Error: " . $e->getMessage();
    }
}

end_of_post:

// 3. FETCH DATA
$search = Validator::sanitizeSearch($_GET['search'] ?? '');

$search_sql = "";
$params = [];
if ($search) {
    $search_sql = "WHERE (e.first_name LIKE ? OR e.last_name LIKE ? OR e.emp_id LIKE ?)";
    $term = "%$search%";
    $params = [$term, $term, $term];
}

$reviewsStmt = $pdo->prepare("
    SELECT p.*, e.first_name, e.last_name, e.emp_id, u.username as reviewer_name
    FROM hr_performance_reviews p
    JOIN employees e ON p.employee_id = e.id
    JOIN users u ON p.reviewer_id = u.id
    $search_sql
    ORDER BY p.review_date DESC
");
$reviewsStmt->execute($params);
$reviewsArray = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch employees for dropdown
$employees = $pdo->query("SELECT id, first_name, last_name, emp_id FROM employees WHERE status = 'Active' ORDER BY last_name ASC")->fetchAll();

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
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Performance Reviews</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
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
            .alert {
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
                width: 27%;
            }

            /* Strengths */
            th:nth-child(5) {
                width: 27%;
            }

            /* Weaknesses */
            th:nth-child(6) {
                width: 6%;
            }

            /* Reviewer */

            /* Add a formal title only visible on paper */
            body::before {
                content: "HR Performance Reviews Report";
                display: block;
                text-align: center;
                font-size: 18pt;
                font-weight: bold;
                margin-bottom: 20px;
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
    </style>
</head>

<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <span class="navbar-text text-white"><i class="bi bi-clipboard2-data-fill"></i> Performance Management</span>
        </div>
    </nav>

    <div class="container">
        <?php if ($msg): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-3">
            <form class="d-flex gap-2" style="width: 400px;">
                <input type="text" name="search" class="form-control" placeholder="Search Employee..." value="<?php echo htmlspecialchars($search); ?>" maxlength="50" list="emp_suggestions" autocomplete="off">
                <datalist id="emp_suggestions">
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']); ?>">
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
                            <th>Review</th>
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
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['reviewer_name']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($reviewsArray) === 0): ?>
                            <tr>
                                <td colspan="6" class="text-center p-4 text-muted">No performance reviews found.</td>
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
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>">
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
                        <label class="form-label">Goals for Next Period</label>
                        <textarea name="goals" class="form-control" rows="3" placeholder="e.g., Complete advanced training course, Lead a small project..." maxlength="5000"></textarea>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Review</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
</body>

</html>