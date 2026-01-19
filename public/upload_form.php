<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload Document</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="bi bi-cloud-arrow-up-fill"></i> Upload Document</h4>
                </div>
                <div class="card-body">

                    <?php if (isset($_GET['error'])): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
                    <?php endif; ?>

                    <form action="process_upload.php" method="POST" enctype="multipart/form-data">
                        
                        <div class="mb-4 position-relative">
                            <label class="form-label fw-bold">Employee <span class="text-danger">*</span></label>
                            
                            <input type="hidden" name="emp_id" id="finalEmpId" value="<?php echo htmlspecialchars($preFilledID); ?>">
                            
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="text" id="employeeSearch" class="form-control" 
                                       placeholder="Search by Name or ID..." 
                                       autocomplete="off"
                                       value="<?php echo htmlspecialchars($preFilledName); ?>"
                                       <?php echo $isLocked ? 'readonly style="background-color: #e9ecef;"' : ''; ?>>
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
                            <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                            <div class="form-text">Allowed: PDF, JPG, PNG</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Custom Filename (Optional)</label>
                            <input type="text" name="custom_filename" class="form-control" placeholder="e.g. 2024_Medical_Cert">
                            <div class="form-text">Leave blank to auto-generate name.</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                                <select name="category" id="categorySelect" class="form-select" required onchange="toggleOtherInput()">
                                    <option value="" disabled selected>-- Select --</option>
                                    <option value="201 Files">201 Files</option>
                                    <option value="Contract">Contract</option>
                                    <option value="Government IDs">Government IDs</option>
                                    <option value="Medical">Medical</option>
                                    <option value="Memo / DA">Memo / DA</option>
                                    <option value="Evaluation">Evaluation</option>
                                    <option value="Certificate">Certificate</option>
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
                            <input type="text" name="other_category" id="otherInput" class="form-control" placeholder="e.g. Gym Membership, Parking Permit...">
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Description / Notes</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Optional details..."></textarea>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="index.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" id="submitBtn" class="btn btn-success px-4" <?php echo empty($preFilledID) ? 'disabled' : ''; ?>>
                                <i class="bi bi-cloud-upload"></i> Upload Now
                            </button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

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
    const searchInput = document.getElementById('employeeSearch');
    const suggestionBox = document.getElementById('suggestionBox');
    const hiddenIdInput = document.getElementById('finalEmpId');
    const submitBtn = document.getElementById('submitBtn');
    
    // If field is readonly (locked), stop here.
    if (searchInput.hasAttribute('readonly')) return;

    let debounceTimer = null;

    // 1. Listen for typing
    searchInput.addEventListener('input', function() {
        const q = this.value.trim();
        
        // Disable submit while typing/searching
        hiddenIdInput.value = ''; 
        submitBtn.disabled = true;

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
        
        // Enable the submit button now that we have a valid ID
        submitBtn.disabled = false;
    }

    // 3. Close suggestions if clicking outside
    document.addEventListener('click', (e) => {
        if (!searchInput.contains(e.target) && !suggestionBox.contains(e.target)) {
            suggestionBox.style.display = 'none';
        }
    });
});
</script>

</body>
</html>