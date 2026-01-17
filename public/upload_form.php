<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

// Check if we are locking to a specific employee
$pre_emp_id = isset($_GET['emp_id']) ? $_GET['emp_id'] : '';
$pre_emp_name = "";
$is_locked = false;

if($pre_emp_id) {
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE emp_id = ?");
    $stmt->execute([$pre_emp_id]);
    $res = $stmt->fetch();
    if($res) {
        $pre_emp_name = $res['first_name'] . " " . $res['last_name'];
        $is_locked = true; // Flag to disable input
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
                    <h5 class="mb-0"><i class="bi bi-cloud-arrow-up"></i> Upload Document</h5>
                </div>
                <div class="card-body">
                    
                    <form action="process_upload.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                        
                        <div class="mb-3 position-relative">
                            <label class="form-label fw-bold">Select Employee <span class="text-danger">*</span></label>
                            
                            <input type="hidden" name="emp_id" id="selected_emp_id" value="<?php echo $pre_emp_id; ?>" required>
                            
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                                <input type="text" id="empSearch" class="form-control" 
                                       placeholder="Type name to search..." 
                                       autocomplete="off" 
                                       value="<?php echo $pre_emp_name; ?>"
                                       <?php echo $is_locked ? 'readonly style="background-color: #e9ecef;"' : ''; ?>>
                                
                                <?php if($is_locked): ?>
                                    <a href="upload_form.php" class="btn btn-outline-secondary" title="Clear Selection">
                                        <i class="bi bi-x-lg"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                            
                            <div id="uploadSuggestionBox" class="list-group position-absolute w-100 shadow" style="z-index: 1000; display: none;"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Select File <span class="text-danger">*</span></label>
                            <input type="file" name="document" id="fileInput" class="form-control" required accept=".pdf,.jpg,.jpeg,.png">
                            
                            <div class="d-flex justify-content-between mt-1">
                                <small class="text-muted"><i class="bi bi-info-circle"></i> Supported: PDF, JPG, PNG</small>
                                <small class="text-muted"><i class="bi bi-hdd"></i> Max Size: 5MB</small>
                            </div>

                            <div id="duplicateWarning" class="alert alert-warning mt-2 py-2" style="display:none; font-size: 0.9rem;">
                                <i class="bi bi-exclamation-triangle-fill"></i> <strong>Warning:</strong> A file with this name already exists. It will be auto-renamed.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Rename File (Optional)</label>
                            <input type="text" name="custom_filename" class="form-control" placeholder="e.g. Contract_2026_Signed">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category</label>
                                <select name="category" class="form-select" required>
                                    <option value="201 Files">201 Files</option>
                                    <option value="Contract">Contract</option>
                                    <option value="Government IDs">Government IDs</option>
                                    <option value="Medical">Medical</option>
                                    <option value="Memo">Memo / DA</option>
                                    <option value="Evaluation">Evaluation</option>
                                    <option value="Certificate">Certificate</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Expiry Date</label>
                                <input type="date" name="expiry_date" class="form-control">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Description / Notes (Visible to Admin)</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Explain what this file is for..."></textarea>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="index.php" class="btn btn-secondary">Cancel</a>
                            <button type="submit" id="submitBtn" class="btn btn-success px-4" disabled>
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
// --- ELEMENTS ---
const empSearch = document.getElementById('empSearch');
const suggestionBox = document.getElementById('uploadSuggestionBox');
const hiddenId = document.getElementById('selected_emp_id');
const fileInput = document.getElementById('fileInput');
const submitBtn = document.getElementById('submitBtn');
const warningBox = document.getElementById('duplicateWarning');
const isLocked = <?php echo $is_locked ? 'true' : 'false'; ?>;

// --- VALIDATION FUNCTION ---
function validateForm() {
    // Enable button ONLY if Employee ID is set AND File is selected
    if (hiddenId.value !== "" && fileInput.value !== "") {
        submitBtn.disabled = false;
    } else {
        submitBtn.disabled = true;
    }
}

// Listen for changes
fileInput.addEventListener('change', validateForm);
// Also check on load (for pre-filled scenarios)
validateForm();

// --- SEARCH LOGIC (Only if not locked) ---
if (!isLocked) {
    empSearch.addEventListener('input', function() {
        let query = this.value;
        
        // Reset hidden ID if typing (force re-selection)
        hiddenId.value = ""; 
        validateForm(); 

        if (query.length < 2) {
            suggestionBox.style.display = 'none';
            return;
        }

        fetch(`api/search_suggestions.php?q=${query}`)
            .then(res => res.json())
            .then(data => {
                suggestionBox.innerHTML = '';
                if (data.length > 0) {
                    suggestionBox.style.display = 'block';
                    data.forEach(emp => {
                        let item = document.createElement('a');
                        item.href = "#"; 
                        item.className = 'list-group-item list-group-item-action border-bottom';
                        item.innerHTML = `
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">${emp.first_name} ${emp.last_name}</h6>
                                <small>${emp.emp_id}</small>
                            </div>`;
                        
                        item.addEventListener('click', function(e) {
                            e.preventDefault();
                            empSearch.value = `${emp.first_name} ${emp.last_name}`;
                            hiddenId.value = emp.emp_id;
                            suggestionBox.style.display = 'none';
                            validateForm(); // Re-check validation
                        });
                        
                        suggestionBox.appendChild(item);
                    });
                } else {
                    suggestionBox.style.display = 'none';
                }
            });
    });
}
</script>

</body>
</html>