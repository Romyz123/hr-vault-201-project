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

checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout and CSRF Generation

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
    $stmt = $pdo->query("SELECT DISTINCT name FROM document_requirements ORDER BY name ASC");
    $dynamicCats = $stmt->fetchAll(PDO::FETCH_COLUMN);
    // Prevent "Others" from duplicating since it's hardcoded at the bottom of the dropdown
    $dynamicCats = array_filter($dynamicCats, function ($cat) {
        return strcasecmp(trim($cat), 'Others') !== 0;
    });
} catch (Exception $e) {
    // Fallback if table doesn't exist yet
    $dynamicCats = ['201 Files', 'Contract', 'Government IDs', 'Medical', 'Memo / DA', 'Evaluation', 'Certificate', 'Training Record'];
}

// [NEW] Pre-fill Category from URL
$preFilledCat = isset($_GET['category']) ? $_GET['category'] : '';

// [NEW] Dynamically calculate Server Upload Limits from php.ini
function return_bytes($val)
{
    $val = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    $val = (int)$val;
    switch ($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }
    return $val;
}
$maxUploadBytes = min(return_bytes(ini_get('upload_max_filesize')), return_bytes(ini_get('post_max_size')));
if ($maxUploadBytes <= 0) $maxUploadBytes = 128 * 1024 * 1024; // Fallback to 128MB
$maxUploadMB = floor($maxUploadBytes / (1024 * 1024));

// [NEW] Fetch Vault Usage Details
$vaultLimitGB = 1; // Default 1GB
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'vault_size_limit_gb'");
    $val = $stmt->fetchColumn();
    if ($val !== false) $vaultLimitGB = (float)$val;
} catch (Exception $e) {
}

$currentVaultBytes = 0;
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;
if (is_dir($vaultPath)) {
    $iterator = new FileSystemIterator($vaultPath, FileSystemIterator::SKIP_DOTS);
    foreach ($iterator as $f) {
        if ($f->isFile()) $currentVaultBytes += $f->getSize();
    }
}
$currentVaultGB = round($currentVaultBytes / 1024 / 1024 / 1024, 2);
$vaultLimitBytes = $vaultLimitGB * 1024 * 1024 * 1024;
$vaultPercent = ($vaultLimitBytes > 0) ? min(100, round(($currentVaultBytes / $vaultLimitBytes) * 100)) : 0;
$isVaultFull = ($vaultLimitBytes > 0 && $currentVaultBytes >= $vaultLimitBytes);
?>
<?php require 'header.php'; ?>

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

    .drop-zone.dragover {
        border-color: #198754;
        /* Success Green */
        background-color: #d1e7dd;
        /* Light Green */
        transform: scale(1.02);
    }

    .drop-zone:hover {
        border-color: #0d6efd;
        background-color: #f8f9fa;
    }

    /* [NEW] Camera Scanning Overlay */
    .camera-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .camera-guide-doc {
        width: 85%;
        height: 60%;
        border: 3px dashed rgba(255, 255, 255, 0.8);
        border-radius: 8px;
        box-shadow: 0 0 0 2000px rgba(0, 0, 0, 0.5);
    }
</style>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-body">

                    <form id="uploadForm" action="process_upload.php" method="POST" enctype="multipart/form-data" onsubmit="return validateAndConfirm()">
                        <!-- [FIX] Dynamically set max file size from PHP configuration -->
                        <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo $maxUploadBytes; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                        <div class="mb-4 position-relative">
                            <label class="form-label fw-bold">Employee <span class="text-danger">*</span></label>

                            <input type="hidden" name="emp_id" id="finalEmpId" value="<?php echo htmlspecialchars($preFilledID); ?>">

                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" id="employeeSearch" class="form-control"
                                    placeholder="Search by Name or ID..."
                                    autocomplete="off"
                                    maxlength="50"
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
                            <div class="d-grid gap-2 mt-2">
                                <button type="button" class="btn btn-outline-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#cameraModal" onclick="startCamera()"><i class="bi bi-camera"></i> Scan Document / ID with Camera</button>
                            </div>
                            <div class="form-text mt-2">Allowed: PDF, JPG, PNG. Max size: <?php echo $maxUploadMB; ?>MB per upload.</div>
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
                        <?php if ($vaultLimitGB > 0): ?>
                            <div class="mt-4 pt-3 border-top">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span><i class="bi bi-hdd-fill"></i> Vault Storage Usage</span>
                                    <span class="<?php echo $isVaultFull ? 'text-danger fw-bold' : ''; ?>"><?php echo number_format($currentVaultGB, 2); ?> GB / <?php echo number_format($vaultLimitGB, 2); ?> GB</span>
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

<!-- [NEW] CAMERA SCANNER MODAL -->
<div class="modal fade" id="cameraModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-upc-scan"></i> Scan Document</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" onclick="stopCamera()"></button>
            </div>
            <div class="modal-body text-center position-relative overflow-hidden p-0 bg-dark">
                <div class="alert alert-dark small rounded-0 mb-0 border-0"><i class="bi bi-info-circle-fill"></i> Align your document or ID card within the frame.</div>
                <video id="cameraVideo" width="100%" autoplay playsinline style="min-height: 400px; max-height: 600px; background: #000; object-fit: contain;"></video>
                <img id="cameraPreviewImage" style="display:none; width: 100%; min-height: 400px; max-height: 600px; object-fit: contain; background: #000;">
                <div class="camera-overlay" id="cameraOverlay">
                    <div class="camera-guide-doc"></div>
                </div>
                <canvas id="cameraCanvas" style="display:none;"></canvas>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="stopCamera()">Cancel</button>
                <div id="cameraControls">
                    <button type="button" class="btn btn-success fw-bold" onclick="capturePhotoPreview()"><i class="bi bi-circle-fill text-danger"></i> Capture Scan</button>
                </div>
                <div id="previewControls" style="display:none;">
                    <button type="button" class="btn btn-warning fw-bold" onclick="retakePhoto()"><i class="bi bi-arrow-counterclockwise"></i> Retake</button>
                    <button type="button" class="btn btn-primary fw-bold" onclick="confirmPhoto()"><i class="bi bi-check-lg"></i> Confirm & Add</button>
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
        // [NEW] Handle URL Messages (Success/Error) on Page Load for Uploads
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('msg')) {
            Swal.fire({
                icon: 'success',
                title: 'Upload Successful',
                text: urlParams.get('msg'),
                timer: 3000,
                showConfirmButton: false
            });
            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('msg');
                window.history.replaceState(null, null, url.toString());
            }
        }
        if (urlParams.has('error')) {
            Swal.fire({
                icon: 'error',
                title: 'Upload Failed',
                text: urlParams.get('error')
            });
            if (window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.delete('error');
                window.history.replaceState(null, null, url.toString());
            }
        }

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
                                    <img src="uploads/avatars/${emp.avatar_path || 'default.png'}" width="30" height="30" class="rounded-circle me-2" onerror="this.onerror=null; this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMDAgMTAwIj48Y2lyY2xlIGN4PSI1MCIgY3k9IjUwIiByPSI1MCIgZmlsbD0iI2UzZTNlMyIvPjxwYXRoIGQ9Ik01MCA1MCBhMjAgMjAgMCAxIDAgMC00MCAyMCAyMCAwIDEgMCAwIDQwIHptMCAxMCBjLTE1IDAtMzUgMTAtMzUgMzAgdjEwIGg3MCB2LTEwIGMtMC0yMC0yMC0zMC0zNS0zMCIgZmlsbD0iI2FhYSIvPjwvc3ZnPg==';">
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
        const MAX_TOTAL_SIZE = <?php echo $maxUploadBytes; ?>; // Dynamic Server Limit
        const maxMB = <?php echo $maxUploadMB; ?>;

        if (fileInput.files.length === 0) {
            document.getElementById('fileListFooter').innerHTML = '';
            return;
        }

        const dt = new DataTransfer();
        let oversizedFound = false;

        Array.from(fileInput.files).forEach((file) => {
            if (file.size > MAX_TOTAL_SIZE) {
                oversizedFound = true;
                return; // Skip oversized file
            }
            dt.items.add(file);
        });

        if (oversizedFound) {
            Swal.fire({
                icon: 'warning',
                title: 'File Too Large',
                text: `One or more files exceeded the maximum upload limit of ${maxMB} MB and were removed from your selection.`
            });
        }

        // Update the input with only the allowed files
        fileInput.files = dt.files;

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
        const limitMB = <?php echo $maxUploadMB; ?>; // Dynamic Server Limit
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

    // [NEW] Allows user to clear the file list if they scanned the wrong picture
    function clearAllFiles() {
        const fileInput = document.getElementById('fileInput');
        const dataTransfer = new DataTransfer();
        fileInput.files = dataTransfer.files; // Clears the input completely
        updateFileList(); // Refreshes the UI to show it's empty
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

    // --- [NEW] CAMERA SCANNING LOGIC ---
    let videoStream = null;
    let capturedBlob = null;

    async function startCamera() {
        const video = document.getElementById('cameraVideo');
        stopCamera(); // Ensure previous stream is killed
        retakePhoto(); // Reset UI

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.error("Camera API not available.");
            Swal.fire({
                icon: 'error',
                title: 'HTTPS Required',
                html: 'Modern browsers strictly block camera access on unsecure (HTTP) networks.<br><br>Please access this system via <b>HTTPS</b> or <b>localhost</b> to use the scanner.',
                confirmButtonColor: '#dc3545'
            });
            const modalEl = document.getElementById('cameraModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
            return;
        }

        try {
            // Request environment camera (back camera) for documents if on mobile
            videoStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: {
                        ideal: "environment"
                    }
                }
            });
            video.srcObject = videoStream;
            video.play().catch(e => console.error("Play error:", e));
        } catch (err) {
            console.error("Camera error:", err);
            Swal.fire('Error', 'Unable to access camera. Please check your browser permissions.', 'error');
            const modalEl = document.getElementById('cameraModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }
        }
    }

    function stopCamera() {
        if (videoStream) {
            videoStream.getTracks().forEach(track => track.stop());
            videoStream = null;
        }
    }

    function capturePhotoPreview() {
        const video = document.getElementById('cameraVideo');
        const canvas = document.getElementById('cameraCanvas');
        if (!videoStream) return;

        // --- VISUAL SHUTTER FLASH ---
        const modalBody = video.closest('.modal-body');
        if (modalBody) {
            const flash = document.createElement('div');
            flash.style.position = 'absolute';
            flash.style.inset = '0';
            flash.style.backgroundColor = '#ffffff';
            flash.style.zIndex = '9999';
            flash.style.transition = 'opacity 0.25s ease-out';
            modalBody.appendChild(flash);
            setTimeout(() => {
                flash.style.opacity = '0';
            }, 10);
            setTimeout(() => {
                flash.remove();
            }, 300);
        }

        let width = video.videoWidth;
        let height = video.videoHeight;

        // [FIX] Fallback if video hasn't loaded metadata properly yet
        if (width === 0 || height === 0) {
            width = video.clientWidth || 640;
            height = video.clientHeight || 480;
        }

        // [FIX] Scale down document to max 1600px to avoid 20MB file sizes and PHP crashes
        const MAX_DIM = 1600;
        if (width > height && width > MAX_DIM) {
            height = Math.round(height * (MAX_DIM / width));
            width = MAX_DIM;
        } else if (height > MAX_DIM) {
            width = Math.round(width * (MAX_DIM / height));
            height = MAX_DIM;
        }

        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, width, height);

        // [FIX] Use Data URL for 100% reliable instant preview on all mobile browsers
        const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
        const previewImg = document.getElementById('cameraPreviewImage');
        if (previewImg) {
            previewImg.src = dataUrl;
            previewImg.style.display = 'block';
        }
        if (video) video.style.display = 'none';
        const overlay = document.getElementById('cameraOverlay');
        if (overlay) overlay.style.display = 'none';
        document.getElementById('cameraControls').style.display = 'none';

        canvas.toBlob(blob => {
            if (!blob) {
                capturedBlob = null;
                const previewImg = document.getElementById('cameraPreviewImage');
                const overlay = document.getElementById('cameraOverlay');
                const video = document.getElementById('cameraVideo');
                if (previewImg) previewImg.style.display = 'none';
                if (overlay) overlay.style.display = 'flex';
                if (video) video.style.display = 'block';
                document.getElementById('cameraControls').style.display = 'block';
                document.getElementById('previewControls').style.display = 'none';
                Swal.fire({
                    icon: 'error',
                    title: 'Scan failed',
                    text: 'Unable to capture the scanned image. Please try again.'
                });
                return;
            }
            capturedBlob = blob;
            document.getElementById('previewControls').style.display = 'block';
        }, 'image/jpeg', 0.85);
    }

    function retakePhoto() {
        capturedBlob = null;
        const previewImg = document.getElementById('cameraPreviewImage');
        const video = document.getElementById('cameraVideo');
        const overlay = document.getElementById('cameraOverlay');
        if (previewImg) previewImg.style.display = 'none';
        if (video) {
            video.style.display = 'block';
            if (video.paused && typeof videoStream !== 'undefined' && videoStream) {
                video.play().catch(e => console.error("Play error:", e));
            }
        }
        if (overlay) overlay.style.display = 'flex';
        document.getElementById('cameraControls').style.display = 'block';
        document.getElementById('previewControls').style.display = 'none';
    }

    function confirmPhoto() {
        if (!capturedBlob) return;
        const file = new File([capturedBlob], "Scanned_Doc_" + Date.now() + ".jpg", {
            type: "image/jpeg"
        });
        const dataTransfer = new DataTransfer();
        const existingFiles = document.getElementById('fileInput').files;
        for (let i = 0; i < existingFiles.length; i++) dataTransfer.items.add(existingFiles[i]); // Keep existing files
        dataTransfer.items.add(file); // Append new scan
        document.getElementById('fileInput').files = dataTransfer.files;
        updateFileList(); // Update UI

        bootstrap.Modal.getInstance(document.getElementById('cameraModal')).hide();
        stopCamera();

        Swal.fire({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 4000,
            icon: 'success',
            title: 'Document scanned! Click "Upload Now" below to save it.'
        });
    }
</script>

</body>

</html>