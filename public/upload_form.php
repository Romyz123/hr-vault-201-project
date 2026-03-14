<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

// Load Config
$config = require '../config/config.php';

// [SECURITY] Require Login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// [SECURITY] Check Maintenance Mode
if (($_SESSION['role'] ?? '') !== 'ADMIN') {
    $chkMaint = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn();
    if ($chkMaint === '1') {
        header("Location: login.php?msg=" . urlencode("🛠️ System is under maintenance."));
        exit;
    }
}

// --- LOGIC: CHECK IF PRE-SELECTED EMPLOYEE EXISTS ---
$preFilledID = '';
$preFilledName = '';
$isLocked = false;

if (isset($_GET['emp_id'])) {
    $target_id = $_GET['emp_id'];
    // Fetch name to show in the box
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE emp_id = ?");
    $stmt->execute([$target_id]);
    $emp = $stmt->fetch();

    if ($emp) {
        $preFilledID = $target_id;
        $preFilledName = $emp['first_name'] . ' ' . $emp['last_name'] . ' (' . $target_id . ')';
        $isLocked = true; // User cannot change this
    }
}

// [NEW] Fetch Dynamic Categories from Tracker Requirements
$dynamicCats = [];
try {
    $stmt = $pdo->query("SELECT name FROM document_requirements ORDER BY name ASC");
    $dynamicCats = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Fallback if table doesn't exist yet
    $dynamicCats = ['201 Files', 'Contract', 'Government IDs', 'Medical', 'Memo / DA', 'Evaluation', 'Certificate', 'Training Record'];
}

// [NEW] Pre-fill Category from URL
$preFilledCat = isset($_GET['category']) ? $_GET['category'] : '';

// [NEW] Fetch Vault Usage Details
$vaultLimitMB = 1024;
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'vault_size_limit_mb'");
    $val = $stmt->fetchColumn();
    if ($val !== false) $vaultLimitMB = (int)$val;
} catch (Exception $e) {}

$currentVaultBytes = 0;
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;
if (is_dir($vaultPath)) {
    $iterator = new FileSystemIterator($vaultPath, FileSystemIterator::SKIP_DOTS);
    foreach ($iterator as $f) {
        if ($f->isFile()) $currentVaultBytes += $f->getSize();
    }
}
$currentVaultMB = round($currentVaultBytes / 1024 / 1024, 2);
$vaultPercent = ($vaultLimitMB > 0) ? min(100, round(($currentVaultMB / $vaultLimitMB) * 100)) : 0;
$isVaultFull = ($vaultLimitMB > 0 && $currentVaultMB >= $vaultLimitMB);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Upload Document</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <style>
        .drop-zone {
            border: 2px dashed #ced4da;
            border-radius: 0.375rem;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.3s ease, background-color 0.3s ease;
            background-color: #fff;
        }

        .drop-zone:hover,
        .drop-zone.dragover {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
    </style>
</head>

<body class="bg-light">

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h4 class="mb-0 fs-5"><i class="bi bi-cloud-arrow-up-fill"></i> Upload Document</h4>
                        <div class="d-flex align-items-center gap-2">
                            <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                                <i class="bi bi-moon-stars-fill"></i>
                            </button>
                            <a href="index.php" class="btn btn-sm btn-outline-light">Back to Dashboard</a>
                        </div>
                    </div>
                    <div class="card-body">

                        <?php if (isset($_GET['error'])): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
                        <?php endif; ?>

                        <form id="uploadForm" action="process_upload.php" method="POST" enctype="multipart/form-data" onsubmit="return validateAndConfirm()">
                            <!-- [FIX] Help PHP handle large files gracefully -->
                            <input type="hidden" name="MAX_FILE_SIZE" value="134217728">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="mb-4 position-relative">
                                <label class="form-label fw-bold">Employee <span class="text-danger">*</span></label>

                                <input type="hidden" name="emp_id" id="finalEmpId" value="<?php echo htmlspecialchars($preFilledID); ?>">

                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" id="employeeSearch" class="form-control"
                                        placeholder="Search by Name or ID..."
                                        autocomplete="off"
                                        maxlength="100"
                                        value="<?php echo htmlspecialchars($preFilledName); ?>"
                                        <?php echo $isLocked ? 'readonly style="background-color: #e9ecef;"' : ''; ?>>
                                </div>
                                <div id="searchFeedback" class="invalid-feedback" style="display:none;">
                                    Please click a name from the dropdown list.
                                </div>

                                <div id="suggestionBox" class="list-group position-absolute w-100 shadow" style="z-index: 1000; display: none;"></div>

                                <div class="form-text text-muted">
                                    <?php if ($isLocked): ?>
                                        <i class="bi bi-lock-fill"></i> Linked to specific employee. <a href="upload_form.php">Click here to upload for someone else.</a>
                                    <?php else: ?>
                                        Start typing to select an employee.
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-bold">Select File <span class="text-danger">*</span></label>
                                <div class="drop-zone" id="dropZone">
                                    <i class="bi bi-cloud-arrow-up display-4 text-secondary"></i>
                                    <p class="fw-bold mt-2 mb-1">Drag & Drop files here</p>
                                    <p class="small text-muted mb-0">or click to browse</p>
                                    <input type="file" name="document[]" id="fileInput" class="d-none" accept=".pdf,.jpg,.jpeg,.png" multiple onchange="updateFileList()">
                                </div>
                                <div id="fileList" class="mt-2 list-group"></div>
                                <div id="fileListFooter"></div>
                                <div class="form-text mt-2">Allowed: PDF, JPG, PNG. Max size: 50MB per file.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Custom Filename (Optional)</label>
                                <input type="text" name="custom_filename" class="form-control" placeholder="e.g. 2024_Medical_Cert" list="filename_suggestions"
                                    maxlength="50"
                                    oninput="this.value = this.value.replace(/[^a-zA-Z0-9\-_ \.]/g, '')">
                                <datalist id="filename_suggestions">
                                    <option value="Medical Certificate">
                                    <option value="NBI Clearance">
                                    <option value="Police Clearance">
                                    <option value="Barangay Clearance">
                                    <option value="Birth Certificate">
                                    <option value="Marriage Contract">
                                    <option value="Diploma / TOR">
                                    <option value="Certificate of Employment">
                                    <option value="SSS Static Info">
                                    <option value="PhilHealth MDR">
                                    <option value="Pag-IBIG MDF">
                                    <option value="TIN ID">
                                </datalist>
                                <div class="form-text">Click the Rename bar and suggest a file name. <strong>Tip:</strong> Include keywords like 'Medical', 'NBI', or 'Contract' to satisfy requirements.</div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                                    <select name="category" id="categorySelect" class="form-select" required onchange="toggleOtherInput()">
                                        <option value="" disabled selected>-- Select Category --</option>
                                        <?php foreach ($dynamicCats as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($preFilledCat === $cat) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                                        <?php endforeach; ?>
                                        <option disabled>──────────</option>
                                        <option value="Others">Others</option>
                                    </select>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Expiry Date</label>
                                    <input type="date" name="expiry_date" class="form-control">
                                </div>
                            </div>

                            <div class="mb-3" id="otherInputDiv" style="display: none;">
                                <label class="form-label fw-bold text-primary">Specify Document Type <span class="text-danger">*</span></label>
                                <input type="text" name="other_category" id="otherInput" class="form-control" placeholder="e.g. Gym Membership, Parking Permit..."
                                    maxlength="50" pattern="[a-zA-Z0-9\-_ ]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores"
                                    oninput="this.value = this.value.replace(/[^a-zA-Z0-9\-_ ]/g, '')">
                            </div>

                            <div class="mb-4">
                                <label class="form-label">Description / Notes</label>
                                <textarea name="description" class="form-control" rows="2" placeholder="Optional details..." maxlength="255" oninput="this.value = this.value.replace(/[<>]/g, '')"></textarea>
                            </div>

                            <div class="input-group mb-3" id="uploadProgressContainer" style="display: none;">
                                <div class="progress" style="height: 38px; flex-grow: 1;">
                                    <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%;">0%</div>
                                </div>
                                <button class="btn btn-danger" type="button" id="cancelUploadBtn"><i class="bi bi-x-lg"></i> Cancel</button>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="index.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" id="submitBtn" class="btn btn-success px-4" <?php echo ($isVaultFull || (empty($preFilledID) && empty($_POST['emp_id']))) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-cloud-upload"></i> Upload Now
                                </button>
                            </div>

                            <!-- [NEW] Vault Space Display -->
                            <?php if ($vaultLimitMB > 0): ?>
                            <div class="mt-4 pt-3 border-top">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span><i class="bi bi-hdd-fill"></i> Vault Storage Usage</span>
                                    <span class="<?php echo $isVaultFull ? 'text-danger fw-bold' : ''; ?>"><?php echo number_format($currentVaultMB, 2); ?> MB / <?php echo number_format($vaultLimitMB); ?> MB</span>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar <?php echo $vaultPercent > 90 ? 'bg-danger' : ($vaultPercent > 75 ? 'bg-warning' : 'bg-success'); ?>" role="progressbar" style="width: <?php echo $vaultPercent; ?>%;"></div>
                                </div>
                                <?php if ($isVaultFull): ?>
                                <div class="text-danger small mt-1"><i class="bi bi-exclamation-triangle-fill"></i> Vault is full. File uploads are disabled.</div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </form>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script>
        // --- A. TOGGLE "OTHERS" INPUT ---
        function toggleOtherInput() {
            const select = document.getElementById('categorySelect');
            const otherDiv = document.getElementById('otherInputDiv');
            const otherInput = document.getElementById('otherInput');

            if (select.value === 'Others') {
                otherDiv.style.display = 'block';
                otherInput.required = true;
            } else {
                otherDiv.style.display = 'none';
                otherInput.required = false;
                otherInput.value = '';
            }
        }

        // --- B. EMPLOYEE SEARCH LOGIC ---
        document.addEventListener('DOMContentLoaded', () => {
            // [FIX] Handle pre-filled "Others" category
            toggleOtherInput();

            const searchInput = document.getElementById('employeeSearch');
            const suggestionBox = document.getElementById('suggestionBox');
            const hiddenIdInput = document.getElementById('finalEmpId');
            const submitBtn = document.getElementById('submitBtn');
            const feedback = document.getElementById('searchFeedback');

            // If field is readonly (locked), stop here.
            if (searchInput.hasAttribute('readonly')) return;

            let debounceTimer = null;

            // 1. Listen for typing
            searchInput.addEventListener('input', function() {
                const q = this.value.trim();

                // Disable submit while typing/searching
                hiddenIdInput.value = '';
                submitBtn.disabled = true;
                feedback.style.display = 'none';

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
                                data.slice(0, 6).forEach(emp => {
                                    // Create Suggestion Item
                                    const item = document.createElement('a');
                                    item.className = 'list-group-item list-group-item-action cursor-pointer';
                                    item.style.cursor = 'pointer';
                                    item.innerHTML = `
                                <div class="d-flex align-items-center">
                                    <img src="uploads/avatars/${emp.avatar_path || ''}" width="30" height="30" class="rounded-circle me-2" onerror="this.src='../assets/default_avatar.png'">
                                    <div>
                                        <strong>${emp.first_name} ${emp.last_name}</strong>
                                        <br><small class="text-muted">${emp.emp_id}</small>
                                    </div>
                                </div>`;

                                    // Click Event
                                    item.onclick = () => {
                                        selectEmployee(emp);
                                    };

                                    suggestionBox.appendChild(item);
                                });
                            } else {
                                suggestionBox.style.display = 'none';
                            }
                        })
                        .catch(err => console.error(err));
                }, 200);
            });

            // 2. Function to Select Employee
            function selectEmployee(emp) {
                searchInput.value = `${emp.first_name} ${emp.last_name} (${emp.emp_id})`;
                hiddenIdInput.value = emp.emp_id; // THIS IS WHAT GETS SENT TO PHP

                suggestionBox.innerHTML = '';
                suggestionBox.style.display = 'none';
                feedback.style.display = 'none';

                // Enable the submit button now that we have a valid ID
                submitBtn.disabled = false;
            }

            // 3. Close suggestions if clicking outside
            document.addEventListener('click', (e) => {
                if (!searchInput.contains(e.target) && !suggestionBox.contains(e.target)) {
                    suggestionBox.style.display = 'none';

                    // [FIX] Warn user if they typed but didn't select
                    if (searchInput.value.trim() !== "" && hiddenIdInput.value === "") {
                        searchInput.classList.add('is-invalid');
                        feedback.style.display = 'block';
                    }
                }
            });
        });

        // [NEW] Drag & Drop Logic (Wrapped in Event Listener for Safety)
        document.addEventListener('DOMContentLoaded', () => {
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('fileInput');

            if (dropZone && fileInput) {
                dropZone.addEventListener('click', (e) => {
                    // Prevent infinite loop if input itself is clicked
                    if (e.target !== fileInput) {
                        fileInput.click();
                    }
                });

                dropZone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    dropZone.classList.add('dragover');
                });

                dropZone.addEventListener('dragleave', () => {
                    dropZone.classList.remove('dragover');
                });

                dropZone.addEventListener('drop', (e) => {
                    e.preventDefault();
                    dropZone.classList.remove('dragover');
                    fileInput.files = e.dataTransfer.files;
                    updateFileList();
                });
            }
        });

        function updateFileList() {
            const fileList = document.getElementById('fileList');
            const fileInput = document.getElementById('fileInput');
            fileList.innerHTML = '';
            let totalSize = 0;
            const MAX_TOTAL_SIZE = 128 * 1024 * 1024; // 128MB

            if (fileInput.files.length === 0) {
                document.getElementById('fileListFooter').innerHTML = '';
                return;
            }

            Array.from(fileInput.files).forEach((file, index) => {
                totalSize += file.size;
                const sizeMB = (file.size / 1024 / 1024).toFixed(2);
                const item = document.createElement('div');
                item.className = 'list-group-item d-flex justify-content-between align-items-center small';

                const nameDiv = document.createElement('div');
                nameDiv.className = 'text-truncate me-2';
                nameDiv.innerHTML = '<i class="bi bi-file-earmark-text me-2 text-primary"></i>';
                nameDiv.appendChild(document.createTextNode(file.name));

                const actionsDiv = document.createElement('div');
                actionsDiv.className = 'd-flex align-items-center';

                actionsDiv.innerHTML = `<span class="badge bg-secondary me-3">${sizeMB} MB</span>`;

                item.appendChild(nameDiv);
                item.appendChild(actionsDiv);
                fileList.appendChild(item);
            });

            // Update Footer (Total Size)
            const footer = document.getElementById('fileListFooter');
            const totalMB = (totalSize / 1024 / 1024).toFixed(2);
            const limitMB = 128; // 128MB limit
            const percent = (totalSize / MAX_TOTAL_SIZE) * 100;

            let colorClass = 'bg-success';
            if (percent > 75) colorClass = 'bg-warning';
            if (percent > 95) colorClass = 'bg-danger';

            let clearBtnHtml = '';
            if (fileInput.files.length > 0) {
                clearBtnHtml = `<button type="button" class="btn btn-sm btn-outline-danger py-0 me-2" onclick="clearAllFiles()" title="Remove all files from list">Clear All</button>`;
            }

            footer.innerHTML = `
                <div class="d-flex justify-content-between mt-2 mb-1 small text-muted">
                    <span>Total Size: <strong>${totalMB} MB</strong> / ${limitMB} MB</span>
                    <span class="d-flex align-items-center">
                        ${clearBtnHtml}
                        <span>${fileInput.files.length} file(s)</span>
                    </span>
                </div>
                <div class="progress" style="height: 4px;">
                    <div class="progress-bar ${colorClass}" role="progressbar" style="width: ${percent}%"></div>
                </div>
            `;
        }

        function validateAndConfirm() {
            const fileInput = document.getElementById('fileInput');
            if (fileInput.files.length === 0) {
                alert('Please select at least one file.');
                return false;
            }
            // Native browser confirmation - 100% reliable blocking
            return confirm('Are you sure you want to upload these files?');
        }
    </script>

    <script>
        // ==========================================
        // [SECURITY] AUTO-LOGOUT (Client-Side)
        // ==========================================

        // TIME SETTING: 30 Minutes
        // 1 second = 1000
        // 15 mins  = 900000
        // 30 mins  = 1800000
        const INACTIVITY_LIMIT = 900000; // 15 Minutes

        let autoLogoutTimer;

        function resetTimer() {
            clearTimeout(autoLogoutTimer);
            autoLogoutTimer = setTimeout(doLogout, INACTIVITY_LIMIT);
        }

        function doLogout() {
            // Redirect to logout page with message
            alert("Session expired due to inactivity.");
            window.location.href = 'logout.php?msg=Session_Expired_Auto';
        }

        // LISTEN FOR ACTIVITY (Resets timer on movement/clicks)
        window.onload = resetTimer;
        document.addEventListener('mousemove', resetTimer);
        document.addEventListener('keydown', resetTimer);
        document.addEventListener('click', resetTimer);
        document.addEventListener('scroll', resetTimer);
    </script>
    <script src="assets/dark_mode.js"></script>
</body>

</html>