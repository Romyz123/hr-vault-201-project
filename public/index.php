<?php
// ======================================================
// TESP HR 201 System - Directory (Refactored)
// ======================================================

// ---- 1) SYSTEM IMPORTS, SECURITY, SESSION ------------
require '../config/db.php';
require '../src/Security.php';
session_start();


// CSRF PROTECTION
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$security = new Security($pdo);
$security->checkRateLimit($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 500, 60); // 500 req/min

// ---- 2) HELPERS --------------------------------------
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function hs($v): string { // for attribute-safe shorter alias
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function getQueryParam(string $key, $default = '') {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}
function keepQuery(array $override = []): string {
    $q = $_GET;
    foreach ($override as $k => $v) {
        if ($v === null) unset($q[$k]); else $q[$k] = $v;
    }
    return '?' . http_build_query($q);
}

// ---- 3) INPUTS / FILTERS / SORT / PAGINATION ----------
$filter_status = getQueryParam('status');
$filter_type   = getQueryParam('type');
$filter_dept   = getQueryParam('dept');
$search_query  = getQueryParam('search');
$sort_option   = getQueryParam('sort', 'newest');

// Whitelist sort
$sortWhitelist = [
    'newest'   => 'hire_date DESC',
    'oldest'   => 'hire_date ASC',
    'alpha_az' => 'last_name ASC, first_name ASC',
    'alpha_za' => 'last_name DESC, first_name DESC'
];
$orderBy = $sortWhitelist[$sort_option] ?? $sortWhitelist['newest'];

// Pagination
$page     = max(1, (int)getQueryParam('page', 1));
$perPage  = max(6, min(48, (int)getQueryParam('per_page', 24)));
$offset   = ($page - 1) * $perPage;

// ---- 4) COMPLIANCE ALERTS (Updated with Specific File Name) ----
$alertDate = date('Y-m-d', strtotime('+30 days'));
$notifyStmt = $pdo->prepare("
    SELECT d.id, d.category, d.original_name, d.expiry_date, e.first_name, e.last_name, e.emp_id 
    FROM documents d 
    JOIN employees e ON d.employee_id = e.emp_id 
    WHERE d.expiry_date IS NOT NULL 
    AND d.expiry_date <= ? 
    AND d.is_resolved = 0 
    ORDER BY d.expiry_date ASC
");
$notifyStmt->execute([$alertDate]);
$notifications = $notifyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$notifyCount   = count($notifications);

// ---- 5) BUILD FILTER SQL (REUSABLE) -------------------
$where = ['1=1'];
$params = [];

if ($filter_status !== '') { $where[] = 'status = ?';           $params[] = $filter_status; }
if ($filter_type   !== '') { $where[] = 'employment_type = ?';  $params[] = $filter_type;   }
if ($filter_dept   !== '') { $where[] = 'dept = ?';             $params[] = $filter_dept;   }

if ($search_query !== '') {
    // Match ID, first, last
    $where[] = '(emp_id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
    $term = "%{$search_query}%";
    array_push($params, $term, $term, $term);
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// ---- 6) TOTAL COUNT (for pagination) ------------------
$countSql = "SELECT COUNT(*) FROM employees {$whereSql}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// Clamp page if out of range
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// ---- 7) FETCH EMPLOYEES PAGE --------------------------
$empSql = "
    SELECT *
    FROM employees
    {$whereSql}
    ORDER BY {$orderBy}
    LIMIT ? OFFSET ?
";
$empParams = array_merge($params, [$perPage, $offset]);
$empStmt = $pdo->prepare($empSql);
$empStmt->execute($empParams);
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ---- 8) BATCH FETCH DOCUMENTS FOR LISTED EMPLOYEES ----
$filesByEmp = [];
if (!empty($employees)) {
    $empIds = array_map(fn($e) => $e['emp_id'], $employees);
    // Prepare safe IN (...) list
    $placeholders = implode(',', array_fill(0, count($empIds), '?'));
    $docsSql = "SELECT * FROM documents WHERE employee_id IN ($placeholders) ORDER BY uploaded_at DESC";
    $docsStmt = $pdo->prepare($docsSql);
    $docsStmt->execute($empIds);
    $docs = $docsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($docs as $d) {
        $filesByEmp[$d['employee_id']][] = $d;
    }
}

// ---- 9) CHART DATA ------------------------------------
$statsQuery = $pdo->query("SELECT category, COUNT(*) as cnt FROM documents GROUP BY category");
$stats = $statsQuery->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$labels = json_encode(array_keys($stats), JSON_UNESCAPED_UNICODE);
$data   = json_encode(array_values($stats), JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TESP HR 201 System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
    <style>
        :root {
            --bg: #f4f6f9;
            --card-border: #e9ecef;
            --accent: #2a5298;
        }
        body { background: var(--bg); }
        .container { max-width: 1200px; }

        .navbar-brand { font-weight: 700; letter-spacing: .2px; }
        .shadow-soft { box-shadow: 0 10px 30px rgba(0,0,0,.05); }
        .avatar-circle {
            width: 86px; height: 86px; border-radius: 50%;
            object-fit: cover; border: 4px solid #fff;
            box-shadow: 0 6px 12px rgba(0,0,0,.12);
            background: #fff;
        }
        .employee-card { cursor: pointer; transition: transform .18s ease, box-shadow .18s ease; border: 1px solid var(--card-border); }
        .employee-card:hover { transform: translateY(-4px); box-shadow: 0 1rem 2rem rgba(0,0,0,.08); }

        .status-active      { border-top: 6px solid #198754; }
        .status-agency      { border-top: 6px solid #ffc107; }
        .status-sick        { border-top: 6px solid #dc3545; }
        .status-terminated  { border-top: 6px solid #000000; }

        .modal-header-custom {
            background: linear-gradient(135deg, #1e3c72 0%, var(--accent) 100%);
            color: #fff;
        }
        .info-label { font-weight: 600; color: #6c757d; font-size: .8rem; text-transform: uppercase; }

        .preview-box {
            height: 520px; border: 2px dashed #dee2e6;
            display: flex; align-items: center; justify-content: center; background: #f8f9fa; border-radius: .5rem;
            color: #6c757d;
        }
        .preview-iframe { width: 100%; height: 100%; border: 0; border-radius: .5rem; }
        .preview-img { max-width: 100%; max-height: 100%; border-radius: .5rem; }

        .list-heading { font-weight: 700; color: #6c757d; text-transform: uppercase; letter-spacing: .02em; }
        .sticky-filters { position: sticky; top: 72px; z-index: 1020; background: #fff; border-bottom: 1px solid var(--card-border); }

        .page-link { border-radius: .4rem; }
        .dropdown-menu { border-radius: .75rem; }
        .card { border-radius: .75rem; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 sticky-top shadow-sm" role="navigation" aria-label="Main navigation">
  <div class="container">
    <a class="navbar-brand" href="#">🏢 TES Philippines HR</a>

    <div class="d-flex align-items-center">
        <div class="dropdown me-3">
            <a href="#" class="text-white text-decoration-none position-relative" data-bs-toggle="dropdown" aria-label="Compliance alerts">
                <i class="bi bi-bell-fill fs-5"></i>
                
                <span id="notifyBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger border border-light"
                      style="display: <?php echo ($notifyCount > 0) ? 'block' : 'none'; ?>">
                    <?php echo (int)$notifyCount; ?>
                </span>
            </a>
            
            <ul id="notifyList" class="dropdown-menu dropdown-menu-end shadow-lg" style="width: 320px; max-height: 400px; overflow-y: auto;">
                <li><h6 class="dropdown-header bg-light border-bottom fw-bold">Compliance Alerts</h6></li>
                
                <?php if ($notifyCount > 0): ?>
                    <?php foreach ($notifications as $notif): 
                        $days = ceil((strtotime($notif['expiry_date']) - time()) / (60 * 60 * 24));
                        $color = ($days < 0) ? 'text-danger' : 'text-warning';
                        $msg = ($days < 0) ? "EXPIRED" : "Expiring in $days days";
                        $icon = ($days < 0) ? 'bi-exclamation-octagon-fill' : 'bi-exclamation-triangle-fill';
                    ?>
                        <li class="border-bottom py-2 px-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <a href="index.php?search=<?php echo $notif['emp_id']; ?>" class="text-decoration-none text-dark w-100">
                                    <div class="d-flex align-items-center">
                                        <i class="bi <?php echo $icon; ?> <?php echo $color; ?> fs-5 me-2"></i>
                                        <div style="line-height: 1.2;">
                                            <small class="fw-bold d-block"><?php echo htmlspecialchars($notif['first_name'] . ' ' . $notif['last_name']); ?></small>
                                            
                                            <span class="text-muted" style="font-size: 0.75rem;">
                                                <?php echo htmlspecialchars($notif['category']); ?>: 
                                                <em class="text-dark"><?php echo htmlspecialchars($notif['original_name']); ?></em>
                                            </span>
                                            
                                            <br><span class="extra-small fw-bold <?php echo $color; ?>"><?php echo $msg; ?></span>
                                        </div>
                                    </div>
                                </a>
                                <button class="btn btn-sm btn-outline-success ms-2" 
                                        onclick="openResolveModal('<?php echo $notif['id']; ?>', '<?php echo htmlspecialchars($notif['original_name']); ?>')"
                                        title="Report Action / Resolve">
                                    <i class="bi bi-check2-circle"></i>
                                </button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="p-4 text-center text-muted small">
                        <i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>
                        All documents are up to date!
                    </li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" id="userMenu" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle fs-5 me-2"></i>
                <strong><?php echo h($_SESSION['username'] ?? 'User'); ?></strong>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><a class="dropdown-item" href="profile_settings.php"><i class="bi bi-gear me-2"></i> Change Password</a></li>
                <?php if (($_SESSION['role'] ?? '') === 'ADMIN'): ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="manager_user.php"><i class="bi bi-people-fill me-2"></i> Manage Users</a></li>
                    <li><a class="dropdown-item" href="activity_logs.php"><i class="bi bi-shield-lock-fill me-2 text-danger"></i> Activity Logs</a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
            </ul>
        </div>
    </div>
  </div>
</nav>

<div class="container">
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show mt-4 shadow-sm" role="alert">
            <i class="bi bi-exclamation-octagon-fill fs-5 me-2"></i> 
            <strong>Action Failed:</strong> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-lg-8 mb-3 mb-lg-0">
            <div class="card h-100 shadow-soft">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-graph-up-arrow me-2 text-primary"></i>
                    <span class="fw-semibold">Document Analytics</span>
                </div>
                <div class="card-body">
                    <canvas id="hrChart" style="max-height: 260px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100 shadow-soft">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-lightning-charge-fill me-2 text-warning"></i>
                    <span class="fw-semibold">Quick Actions</span>
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="upload_form.php" class="btn btn-primary">
                        <i class="bi bi-cloud-arrow-up"></i> Upload Document
                    </a>
                    <a href="add_employee.php" class="btn btn-success">
                        <i class="bi bi-person-plus-fill"></i> Add Employee
                    </a>


                 <button type="button" class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#exportModal">
    <i class="bi bi-file-earmark-zip-fill text-warning"></i> Export Files (ZIP)
</button>
                    
                    <?php if (in_array($_SESSION['role'] ?? '', ['ADMIN', 'HR'], true)): ?>
                        <a href="admin_approval.php" class="btn btn-outline-danger">
                            <i class="bi bi-shield-lock"></i> Pending Requests
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4 shadow-soft">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="text-muted mb-0"><i class="bi bi-funnel-fill"></i> Directory Search</h5>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset Filters</a>
            </div>

            <form action="index.php" method="GET" class="row g-2">
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <?php
                        $statuses = ['Active' => '✅ Active', 'Resigned' => '❌ Resigned', 'Terminated' => '🛑 Terminated', 'AWOL' => '🚫 AWOL'];
                        foreach ($statuses as $val => $label) {
                            $sel = ($filter_status === $val) ? 'selected' : '';
                            echo "<option value=\"".hs($val)."\" {$sel}>".h($label)."</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Types</option>
                        <?php
                        $types = ['TESP Direct' => 'TESP Direct', 'Agency' => 'Agency (All)'];
                        foreach ($types as $val => $label) {
                            $sel = ($filter_type === $val) ? 'selected' : '';
                            echo "<option value=\"".hs($val)."\" {$sel}>".h($label)."</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <select name="dept" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php
                        $depts = ['ADMIN','HMS','RAS','TRS','LMS','DOS','SQP','CTS','SIGCOM','PSS','OCS','BFS','WHS','GUNJIN'];
                        foreach ($depts as $d) {
                            $sel = ($filter_dept === $d) ? 'selected' : '';
                            echo "<option value=\"".hs($d)."\" {$sel}>".h($d)."</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <select name="sort" class="form-select form-select-sm fw-bold text-primary" onchange="this.form.submit()">
                        <option value="newest"   <?php echo ($sort_option==='newest')?'selected':''; ?>>📅 Newest</option>
                        <option value="oldest"   <?php echo ($sort_option==='oldest')?'selected':''; ?>>📅 Oldest</option>
                        <option value="alpha_az" <?php echo ($sort_option==='alpha_az')?'selected':''; ?>>🔤 Name (A-Z)</option>
                        <option value="alpha_za" <?php echo ($sort_option==='alpha_za')?'selected':''; ?>>🔤 Name (Z-A)</option>
                    </select>
                </div>

                <div class="col-md-3 position-relative">
                    <div class="input-group input-group-sm">
                        <input type="text" id="mainSearch" name="search" class="form-control" placeholder="Search by ID / First / Last..." value="<?php echo h($search_query); ?>" autocomplete="off" aria-label="Search employees">
                        <button class="btn btn-primary" type="submit" aria-label="Submit search"><i class="bi bi-search"></i></button>

                        <button type="button" class="btn btn-success dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Export options"><i class="bi bi-download"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end shadow p-3" style="width: 260px;">
                            <li><h6 class="dropdown-header text-primary"><i class="bi bi-info-circle"></i> Export Options</h6></li>
                            <li><p class="small text-muted mb-2 text-wrap">Download the currently filtered list.</p></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><button type="submit" formaction="export_employees.php" class="dropdown-item"><i class="bi bi-file-earmark-spreadsheet text-success me-2"></i> Save as Excel</button></li>
                            <li><button type="submit" formaction="print_list.php" formtarget="_blank" class="dropdown-item"><i class="bi bi-file-earmark-pdf text-danger me-2"></i> Print / PDF</button></li>
                        </ul>
                    </div>
                    <div id="suggestionBox" class="list-group position-absolute w-100 shadow" style="z-index: 1000; display: none; top: 35px;"></div>
                </div>

                <input type="hidden" name="page" value="<?php echo (int)$page; ?>">
                <input type="hidden" name="per_page" value="<?php echo (int)$perPage; ?>">
            </form>
        </div>
    </div>

    <?php if (empty($employees)): ?>
        <div class="alert alert-warning text-center shadow-sm">No employees found matching your search.</div>
    <?php endif; ?>

    <div class="row">
        <?php foreach ($employees as $emp):
            $statusClass = match ($emp['status']) {
                'Active'     => 'status-active',
                'Resigned'   => 'status-agency',
                'Terminated' => 'status-terminated',
                default      => 'border-secondary'
            };
            $statusBadge = match ($emp['status']) {
                'Active'     => 'bg-success',
                'Resigned'   => 'bg-warning',
                'Terminated' => 'bg-dark',
                default      => 'bg-secondary'
            };

            if (($emp['employment_type'] ?? '') === 'TESP Direct') {
                $employerBadge = '<span class="badge bg-primary">TESP DIRECT</span>';
            } else {
                $agencyName = !empty($emp['agency_name']) ? strtoupper($emp['agency_name']) : 'AGENCY';
                $employerBadge = '<span class="badge bg-warning text-dark">'.h($agencyName).'</span>';
            }

            $deptDisplay = h($emp['dept']);
            if (!empty($emp['section']) && $emp['section'] !== 'Main Unit') {
                $deptDisplay .= ' > ' . h($emp['section']);
            }

            $files = $filesByEmp[$emp['emp_id']] ?? [];
            $modalId = 'viewModal' . (int)$emp['id'];
            $previewBoxId = 'preview-' . (int)$emp['id'];
        ?>
        <div class="col-md-6 col-lg-4 mb-4">
            <div class="card h-100 employee-card <?php echo $statusClass; ?>" role="button" data-bs-toggle="modal" data-bs-target="#<?php echo hs($modalId); ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="me-3">
                            <img src="uploads/avatars/<?php echo h($emp['avatar_path'] ?: ''); ?>" class="avatar-circle" onerror="this.src='../assets/default_avatar.png';" alt="Avatar">
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="card-title mb-1 fw-bold"><?php echo h($emp['first_name'] . ' ' . $emp['last_name']); ?></h5>
                            <small class="text-muted d-block mb-1"><?php echo $deptDisplay; ?></small>
                            <span class="badge <?php echo $statusBadge; ?> rounded-pill"><?php echo h($emp['status']); ?></span>
                        </div>
                        <div class="d-flex flex-column align-items-end">
                            <div class="mb-2"><?php echo $employerBadge; ?></div>

                            <a href="print_employee.php?id=<?php echo (int)$emp['id']; ?>" class="btn btn-sm btn-outline-dark py-0 px-2 mt-1" target="_blank" onclick="event.stopPropagation();" aria-label="Print employee">
                                <i class="bi bi-printer-fill"></i>
                            </a>

                            <?php if (isset($_SESSION['user_id'])): ?>
                                <a href="edit_employee.php?id=<?php echo (int)$emp['id']; ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 mt-1" onclick="event.stopPropagation();" aria-label="Edit employee">
                                    <i class="bi bi-pencil-square"></i> Edit
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="<?php echo hs($modalId); ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header modal-header-custom p-4">
                            <div class="d-flex align-items-center w-100">
                                <img src="uploads/avatars/<?php echo h($emp['avatar_path'] ?: ''); ?>" class="rounded-circle border border-3 border-white shadow-sm" width="100" height="100" onerror="this.src='../assets/default_avatar.png';" alt="Avatar">
                                <div class="ms-3 flex-grow-1">
                                    <h3 class="mb-0 fw-bold"><?php echo h($emp['first_name'] . ' ' . $emp['last_name']); ?></h3>
                                    <div class="badge bg-light text-dark mt-1"><?php echo h($emp['emp_id']); ?></div>
                                    <div class="badge bg-white text-dark mt-1"><?php echo h($emp['job_title']); ?></div>
                                </div>
                                <button type="button" class="btn-close btn-close-white align-self-start" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                        </div>
                        <div class="modal-body bg-light p-0">
                            <div class="d-flex h-100">
                                <div class="nav flex-column nav-pills p-3 bg-white border-end" style="width: 260px;">
                                    <button class="nav-link active text-start mb-2" data-bs-toggle="pill" data-bs-target="#info-<?php echo (int)$emp['id']; ?>">Profile</button>
                                    <button class="nav-link text-start" data-bs-toggle="pill" data-bs-target="#files-<?php echo (int)$emp['id']; ?>">Documents (<?php echo (int)count($files); ?>)</button>
                                </div>
                                <div class="tab-content flex-grow-1 p-4">
                                    <div class="tab-pane fade show active" id="info-<?php echo (int)$emp['id']; ?>">
                                        <div class="row g-3">
                                            <div class="col-6"><span class="info-label">Department:</span><br><?php echo h($emp['dept']); ?></div>
                                            <div class="col-6"><span class="info-label">Section:</span><br><?php echo h($emp['section']); ?></div>
                                            <div class="col-6"><span class="info-label">Contact:</span><br><?php echo h($emp['contact_number']); ?></div>
                                            <div class="col-6"><span class="info-label">Email:</span><br><?php echo h($emp['email']); ?></div>
                                            <div class="col-12"><span class="info-label">Address:</span><br><?php echo h($emp['present_address']); ?></div>
                                        </div>
                                    </div>
                                    <div class="tab-pane fade" id="files-<?php echo (int)$emp['id']; ?>">
                                        <div class="row h-100">
                                            <div class="col-4 border-end">
                                                <div class="d-grid gap-2 mb-3">
                                                    <a href="upload_form.php?emp_id=<?php echo hs($emp['emp_id']); ?>" class="btn btn-primary btn-sm">
                                                        <i class="bi bi-cloud-arrow-up-fill"></i> Upload New File
                                                    </a>
                                                </div>
                                                <div class="list-group">
                                                <?php foreach($files as $file):
                                                    $previewUrl = "view_doc.php?id=" . $file['file_uuid'];
                                                    $type = (stripos($file['original_name'], '.pdf') !== false) ? 'pdf' : 'img';
                                                    $previewTarget = 'preview-' . (int)$emp['id']; 
                                                ?>
                                                <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center p-2">
                                                    
                                                    <a href="javascript:void(0);" 
                                                       class="text-decoration-none text-dark text-truncate w-75"
                                                       onclick="showPreview('<?php echo $previewUrl; ?>', '<?php echo $type; ?>', '<?php echo $previewTarget; ?>'); return false;">
                                                        <strong><?php echo htmlspecialchars($file['original_name']); ?></strong><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($file['category']); ?></small>
                                                    </a>

                                                    <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['ADMIN', 'HR'])): ?>
                                                        <form action="delete_document.php" method="POST" onsubmit="return confirm('Permanently delete this file?');" class="m-0">
                                                            <input type="hidden" name="file_uuid" value="<?php echo $file['file_uuid']; ?>">
                                                            <input type="hidden" name="emp_id" value="<?php echo $emp['emp_id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger border-0" title="Delete File">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>

                                                </div>
                                                <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div class="col-8">
                                                <div id="<?php echo hs($previewBoxId); ?>" class="preview-box">Select a file to preview</div>
                                            </div>
                                        </div>
                                    </div> </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div> </div>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav class="mt-3" aria-label="Employee pagination">
        <ul class="pagination justify-content-center">
            <?php
            $prevDisabled = ($page <= 1) ? ' disabled' : '';
            $nextDisabled = ($page >= $totalPages) ? ' disabled' : '';
            ?>
            <li class="page-item<?php echo $prevDisabled; ?>">
                <a class="page-link" href="<?php echo hs(keepQuery(['page' => max(1, $page-1)])); ?>" aria-label="Previous">
                    <span aria-hidden="true">&laquo;</span>
                </a>
            </li>

            <?php
            // windowed pagination
            $window = 2;
            $start = max(1, $page - $window);
            $end   = min($totalPages, $page + $window);
            if ($start > 1) {
                echo '<li class="page-item"><a class="page-link" href="'.hs(keepQuery(['page' => 1])).'">1</a></li>';
                if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            for ($p = $start; $p <= $end; $p++) {
                $active = ($p === $page) ? ' active' : '';
                echo '<li class="page-item'.$active.'"><a class="page-link" href="'.hs(keepQuery(['page' => $p])).'">'.(int)$p.'</a></li>';
            }
            if ($end < $totalPages) {
                if ($end < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                echo '<li class="page-item"><a class="page-link" href="'.hs(keepQuery(['page' => $totalPages])).'">'.(int)$totalPages.'</a></li>';
            }
            ?>

            <li class="page-item<?php echo $nextDisabled; ?>">
                <a class="page-link" href="<?php echo hs(keepQuery(['page' => min($totalPages, $page+1)])); ?>" aria-label="Next">
                    <span aria-hidden="true">&raquo;</span>
                </a>
            </li>
        </ul>
        <p class="text-center text-muted small mb-0">
            Showing <strong><?php echo (int)count($employees); ?></strong> of <strong><?php echo (int)$totalRows; ?></strong> employees — Page <?php echo (int)$page; ?> / <?php echo (int)$totalPages; ?>
        </p>
    </nav>
    <?php endif; ?>

</div> <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ---------------- Chart ----------------
document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('hrChart');
    if (!ctx) return;

    const labels = <?php echo $labels ?: '[]'; ?>;
    const values = <?php echo $data   ?: '[]'; ?>;

    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Documents',
                data: values,
                backgroundColor: (ctx) => {
                    const palette = ['#4BC0C0','#36A2EB','#FFCE56','#9966FF','#FF9F40','#FF6384'];
                    return ctx.dataIndex != null ? palette[ctx.dataIndex % palette.length] : '#36A2EB';
                },
                borderRadius: 6,
                barPercentage: .6,
                maxBarThickness: 54
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: '#6c757d' } },
                y: { beginAtZero: true, ticks: { precision: 0, color: '#6c757d' }, grid: { color: 'rgba(0,0,0,.05)' } }
            }
        }
    });
});

// ---------------- Typeahead Suggestions ----------------
(() => {
    const searchInput = document.getElementById('mainSearch');
    const suggestionBox = document.getElementById('suggestionBox');
    if (!searchInput || !suggestionBox) return;

    let debounceTimer = null;

    searchInput.addEventListener('input', function() {
        const q = this.value.trim();
        if (q.length < 2) {
            suggestionBox.innerHTML = '';
            suggestionBox.style.display = 'none';
            return;
        }

        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            fetch(`api/search_suggestions.php?q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(data => {
                    suggestionBox.innerHTML = '';
                    if (Array.isArray(data) && data.length > 0) {
                        suggestionBox.style.display = 'block';
                        data.slice(0, 8).forEach(emp => {
                            const a = document.createElement('a');
                            a.href = `index.php?search=${emp.emp_id}`;
                            a.className = 'list-group-item list-group-item-action d-flex align-items-center';
                            a.innerHTML = `<img src="uploads/avatars/${emp.avatar_path||''}" width="30" height="30" class="rounded-circle me-2" onerror="this.src='../assets/default_avatar.png'">
                                           <div><strong>${emp.first_name} ${emp.last_name}</strong><br><small class="text-muted">${emp.emp_id}</small></div>`;
                            suggestionBox.appendChild(a);
                        });
                    } else {
                        suggestionBox.style.display = 'none';
                    }
                })
                .catch(() => {});
        }, 180);
    });

    document.addEventListener('click', (e) => {
        if (!searchInput.contains(e.target) && !suggestionBox.contains(e.target)) {
            suggestionBox.style.display = 'none';
        }
    });
})();

// ---------------- Document Preview (GLOBAL) ----------------
function showPreview(url, type, containerId) {
    console.log("Previewing:", url, type, containerId);

    const container = document.getElementById(containerId);
    if (!container) {
        console.error("Preview container not found: " + containerId);
        return;
    }

    // 1. Show Loading
    container.innerHTML = '<div class="d-flex justify-content-center align-items-center h-100 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2"></div> Loading...</div>';

    // 2. Load Content
    setTimeout(() => {
        container.innerHTML = ''; 
        if (type === 'pdf') {
            const iframe = document.createElement('iframe');
            iframe.src = url;
            iframe.className = 'preview-iframe';
            iframe.style.width = "100%";
            iframe.style.height = "100%";
            iframe.style.border = "none";
            iframe.style.borderRadius = "8px";
            container.appendChild(iframe);
        } else {
            const img = document.createElement('img');
            img.src = url;
            img.className = 'preview-img';
            img.style.maxWidth = "100%";
            img.style.maxHeight = "100%";
            img.style.objectFit = "contain";
            img.alt = 'Preview';
            container.appendChild(img);
        }
    }, 200);
}

// ---------------- Resolve Modal Logic ----------------
function openResolveModal(id, fileName) {
    const idField = document.getElementById('res_doc_id');
    const nameField = document.getElementById('res_cat_name');
    const modalEl = document.getElementById('resolveModal');

    if(idField && nameField && modalEl) {
        idField.value = id;
        nameField.innerText = fileName; // Shows specific file name now!
        new bootstrap.Modal(modalEl).show();
    }
}

// ---------------- Real-Time Updates (AJAX) ----------------
// Checks for updates every 5 seconds
setInterval(fetchDashboardUpdates, 5000);

function fetchDashboardUpdates() {
    fetch('api/get_updates.php')
        .then(response => response.json())
        .then(data => {
            // 1. Update Notification Badge
            const badge = document.getElementById('notifyBadge');
            if (badge) {
                badge.innerText = data.count;
                badge.style.display = (data.count > 0) ? 'block' : 'none';
            }

            // 2. Update Notification List (Dropdown)
            const list = document.getElementById('notifyList');
            if (list) {
                // Only update HTML if the count changed to prevent flicker
                if (list.getAttribute('data-last-count') != data.count) {
                    list.innerHTML = data.html;
                    list.setAttribute('data-last-count', data.count);
                }
            }

            // 3. Update Chart
            const chartInstance = Chart.getChart("hrChart");
            if (chartInstance) {
                // Check if data is different
                if (JSON.stringify(chartInstance.data.datasets[0].data) !== JSON.stringify(data.chartValues)) {
                    chartInstance.data.labels = data.chartLabels;
                    chartInstance.data.datasets[0].data = data.chartValues;
                    chartInstance.update();
                }
            }
        })
        .catch(err => console.error("Update error:", err));
}
// Resolve Modal
function openResolveModal(id, name) {
    document.getElementById('res_doc_id').value = id;
    document.getElementById('res_cat_name').innerText = name;
    new bootstrap.Modal(document.getElementById('resolveModal')).show();
}
</script>

<div class="modal fade" id="resolveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Report Action Taken</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="submit_resolution.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="doc_id" id="res_doc_id">
                    <p>Resolving alert for: <strong id="res_cat_name"></strong></p>
                    <textarea name="resolution_note" class="form-control" rows="3" required placeholder="Action taken..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="export_files.php" method="POST" class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-archive-fill"></i> Bulk Export</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                
                <div class="mb-3 p-2 bg-light border rounded position-relative">
                    <label class="form-label fw-bold text-primary">Search Employee (Optional)</label>
                    <input type="text" id="exportSearch" name="search" class="form-control" 
                           placeholder="Type Name or ID..." minlength="2" maxlength="50" autocomplete="off">
                    
                    <div id="exportSuggestionBox" class="list-group position-absolute w-100 shadow" 
                         style="display: none; z-index: 2000; top: 75px; max-height: 200px; overflow-y: auto;">
                    </div>
                    
                    <div class="form-text small">Typing a name makes "Department" optional.</div>
                </div>

                <hr>

                <div class="mb-3">
                    <label class="form-label fw-bold">Department</label>
                    <select name="dept" id="exportDept" class="form-select">
                        <option value="" selected>-- Select Scope --</option>
                        <option value="ALL" class="fw-bold text-danger">-- ENTIRE DATABASE --</option>
                        <option value="ADMIN">ADMIN</option>
                        <option value="HMS">HMS</option>
                        <option value="RAS">RAS</option>
                        <option value="TRS">TRS</option>
                        <option value="LMS">LMS</option>
                        <option value="DOS">DOS</option>
                        <option value="SQP">SQP</option>
                        <option value="CTS">CTS</option>
                        <option value="SIGCOM">SIGCOM</option>
                        <option value="PSS">PSS</option>
                        <option value="OCS">OCS</option>
                        <option value="BFS">BFS</option>
                        <option value="WHS">WHS</option>
                        <option value="GUNJIN">GUNJIN</option>
                        <option value="SUBCONS-OTHERS">SUBCONS-OTHERS</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Section (Filtered by Dept)</label>
                    <select name="section" id="exportSection" class="form-select" disabled>
                        <option value="">-- All Sections --</option>
                        
                        <optgroup label="ADMIN">
                            <option value="GAG">GAG</option>
                            <option value="TKG">TKG</option>
                            <option value="PCG">PCG</option>
                            <option value="ACG">ACG</option>
                            <option value="MED">MED</option>
                            <option value="OP">OP</option>
                            <option value="CLEANERS/HOUSE KEEPING">CLEANERS</option>
                        </optgroup>

                        <optgroup label="HMS"><option value="HEAVY MAINTENANCE SECTION">HEAVY MAINTENANCE SECTION</option></optgroup>
                        <optgroup label="RAS"><option value="ROOT CAUSE ANALYSIS SECTION">ROOT CAUSE ANALYSIS SECTION</option></optgroup>
                        <optgroup label="TRS"><option value="TECHNICAL RESEARCH SECTION">TECHNICAL RESEARCH SECTION</option></optgroup>
                        <optgroup label="LMS"><option value="LIGHT MAINTENANCE SECTION">LIGHT MAINTENANCE SECTION</option></optgroup>
                        <optgroup label="DOS"><option value="DEPARTMENT OPERATIONS SECTION">DEPARTMENT OPERATIONS SECTION</option></optgroup>
                        
                        <optgroup label="SQP">
                            <option value="SAFETY">SAFETY</option>
                            <option value="QA">QA</option>
                            <option value="PLANNING">PLANNING</option>
                            <option value="IT">IT</option>
                        </optgroup>

                        <optgroup label="CTS"><option value="CIVIL TRACKS SECTION">CIVIL TRACKS SECTION</option></optgroup>
                        <optgroup label="SIGCOM"><option value="SIGNALING COMMUNICATION">SIGNALING COMMUNICATION</option></optgroup>
                        <optgroup label="PSS"><option value="POWER SUPPLY SECTION">POWER SUPPLY SECTION</option></optgroup>
                        <optgroup label="OCS"><option value="OVERHEAD CANERARY SECTION">OVERHEAD CANERARY SECTION</option></optgroup>
                        <optgroup label="BFS"><option value="BUILDING FACILITIES SECTION">BUILDING FACILITIES SECTION</option></optgroup>
                        <optgroup label="WHS"><option value="WAREHOUSE">WAREHOUSE</option></optgroup>
                        
                        <optgroup label="GUNJIN">
                            <option value="EMT">EMT</option>
                            <option value="SECURITY PERSONNEL">SECURITY PERSONNEL</option>
                        </optgroup>
                        
                        <optgroup label="SUBCONS-OTHERS"><option value="OTHERS">OTHERS</option></optgroup>
                    </select>
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Agency</label>
                        <select name="employment_type" class="form-select">
                            <option value="">-- All --</option>
                            <option value="TESP DIRECT">TESP DIRECT</option>
                            <option value="GUNJIN">GUNJIN</option>
                            <option value="JORATECH">JORATECH</option>
                            <option value="UNLISOLUTIONS">UNLISOLUTIONS</option>
                            <option value="OTHERS - SUBCONS">OTHERS - SUBCONS</option>
                        </select>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label fw-bold">Category</label>
                        <select name="category" class="form-select">
                            <option value="">-- All --</option>
                            <option value="201 Files">201 Files</option>
                            <option value="Medical">Medical</option>
                            <option value="Contract">Contract</option>
                            <option value="Evaluation">Evaluation</option>
                            <option value="Certificate">Certificate</option>
                            <option value="Others">Others</option>
                        </select>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-download"></i> Download ZIP</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>


<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="export_files.php" method="POST" class="modal-content" target="_blank">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-archive-fill"></i> Bulk Export</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                
                <div class="mb-3 p-2 bg-light border rounded position-relative">
                    <label class="form-label fw-bold text-primary">Search Employee (Optional)</label>
                    <input type="text" id="exportSearch" name="search" class="form-control" 
                           placeholder="Type Name or ID..." autocomplete="off">
                    
                    <div id="exportSuggestionBox" class="list-group position-absolute w-100 shadow" 
                         style="display: none; z-index: 2000; top: 75px;"></div>
                    
                    <div class="form-text small">Typing a name makes "Department" optional.</div>
                </div>

                <hr>

                <div class="mb-3">
                    <label class="form-label fw-bold">Department</label>
                    <select name="dept" id="exportDept" class="form-select">
                        <option value="" selected>-- Select Scope --</option>
                        <option value="ALL" class="fw-bold text-danger">-- ENTIRE DATABASE --</option>
                        <option value="ADMIN">ADMIN</option>
                        <option value="HMS">HMS</option>
                        <option value="RAS">RAS</option>
                        <option value="TRS">TRS</option>
                        <option value="LMS">LMS</option>
                        <option value="DOS">DOS</option>
                        <option value="SQP">SQP</option>
                        <option value="CTS">CTS</option>
                        <option value="SIGCOM">SIGCOM</option>
                        <option value="PSS">PSS</option>
                        <option value="OCS">OCS</option>
                        <option value="BFS">BFS</option>
                        <option value="WHS">WHS</option>
                        <option value="GUNJIN">GUNJIN</option>
                        <option value="SUBCONS-OTHERS">SUBCONS-OTHERS</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Section (Filtered by Dept)</label>
                    <select name="section" id="exportSection" class="form-select" disabled>
                        <option value="">-- All Sections --</option>
                        <optgroup label="ADMIN">
                            <option value="GAG">GAG</option>
                            <option value="TKG">TKG</option>
                            <option value="PCG">PCG</option>
                            <option value="ACG">ACG</option>
                            <option value="MED">MED</option>
                            <option value="OP">OP</option>
                            <option value="CLEANERS/HOUSE KEEPING">CLEANERS</option>
                        </optgroup>
                        <optgroup label="SQP">
                            <option value="SAFETY">SAFETY</option>
                            <option value="QA">QA</option>
                            <option value="PLANNING">PLANNING</option>
                            <option value="IT">IT</option>
                        </optgroup>
                        </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Category</label>
                    <select name="category" class="form-select">
                        <option value="">-- All --</option>
                        <option value="201 Files">201 Files</option>
                        <option value="Medical">Medical</option>
                        <option value="Contract">Contract</option>
                        <option value="Evaluation">Evaluation</option>
                        <option value="Certificate">Certificate</option>
                        <option value="Others">Others</option>
                    </select>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-download"></i> Download ZIP</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // 1. SMART DROPDOWNS
    const deptSelect = document.getElementById('exportDept');
    const sectSelect = document.getElementById('exportSection');
    if (deptSelect && sectSelect) {
        const sectOptGroups = sectSelect.querySelectorAll('optgroup');
        deptSelect.addEventListener('change', function() {
            const selectedDept = this.value;
            if (selectedDept && selectedDept !== 'ALL') {
                sectSelect.disabled = false;
                sectSelect.value = "";
                sectOptGroups.forEach(group => {
                    group.style.display = (group.label === selectedDept) ? '' : 'none';
                });
            } else {
                sectSelect.disabled = true;
                sectSelect.value = "";
            }
        });
    }

    // 2. SEARCH SUGGESTIONS
    const input = document.getElementById('exportSearch');
    const box = document.getElementById('exportSuggestionBox');
    
    if (input && box) {
        let timer;
        input.addEventListener('input', function() {
            const q = this.value.trim();
            if (q.length < 2) { box.style.display = 'none'; return; }
            clearTimeout(timer);
            timer = setTimeout(() => {
                fetch(`api/search_suggestions.php?q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(data => {
                        box.innerHTML = '';
                        if (data.length > 0) {
                            box.style.display = 'block';
                            data.forEach(emp => {
                                const item = document.createElement('a');
                                item.className = 'list-group-item list-group-item-action';
                                item.style.cursor = 'pointer';
                                item.innerHTML = `<strong>${emp.first_name} ${emp.last_name}</strong> <small class='text-muted'>${emp.emp_id}</small>`;
                                item.onclick = function() {
                                    input.value = emp.emp_id;
                                    box.style.display = 'none';
                                };
                                box.appendChild(item);
                            });
                        } else { box.style.display = 'none'; }
                    });
            }, 200);
        });
        document.addEventListener('click', (e) => {
            if (!input.contains(e.target) && !box.contains(e.target)) { box.style.display = 'none'; }
        });
    }
});
</script>
</body>
</html>
