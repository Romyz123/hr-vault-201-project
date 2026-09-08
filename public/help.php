<?php
// ======================================================
// [FILE] public/help.php
// [PURPOSE] User-Friendly Feature Guide & System Manual
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// Security: Require Login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userRole = strtoupper(trim($_SESSION['role'] ?? 'STAFF'));
?>
<?php require 'header.php'; ?>

<style>
    .help-hero {
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        color: #000000;
        border-radius: 1rem;
        padding: 2.5rem 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 10px 25px rgba(30, 60, 114, 0.15);
    }

    .feature-card {
        border: none;
        border-radius: 0.85rem;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
        height: 100%;
        background: var(--bs-card-bg, #000000);
    }

    .feature-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(0, 0, 0, 0.08) !important;
    }

    .icon-box {
        width: 54px;
        height: 54px;
        border-radius: 0.75rem;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.6rem;
        margin-bottom: 1rem;
    }

    .step-badge {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #0d6efd;
        color: white;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 0.85rem;
        margin-right: 0.5rem;
    }

    .role-pill {
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.25rem 0.6rem;
        border-radius: 50rem;
    }

    .search-highlight {
        background-color: #fff3cd;
        padding: 0.1rem 0.3rem;
        border-radius: 0.2rem;
    }
</style>

<div class="container pb-5" style="max-width: 1100px;">
    <!-- 1. HERO BANNER & QUICK SEARCH -->
    <div class="help-hero text-center" style="color: #ffffff;">
        <!-- Black/Dark Text on White Rounded Pill -->
        <div class="d-inline-block px-3 py-1 bg-white rounded-pill mb-3 text-dark small fw-bold shadow-sm">
            <i class="bi bi-patch-check-fill me-1 text-primary"></i> User Manual & System Guide
        </div>
        <h1 class="fw-bold mb-2 text-white"><i class="bi bi-journal-richtext me-2"></i>HR Vault 201 Help Center</h1>
        <p class="lead opacity-90 mx-auto mb-4 text-white" style="max-width: 650px;">
            Everything you need to know about managing 201 records, analytics, document contracts, performance, and approvals.
        </p>

        <!-- Search Input with Length Limitation & Sanitization -->
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-6">
                <div class="input-group input-group-lg shadow-sm rounded-pill overflow-hidden">
                    <span class="input-group-text bg-white border-0 ps-3 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text"
                        id="helpSearch"
                        class="form-control border-0 pe-3 fs-6"
                        placeholder="Search a feature (e.g. Analytics, Add Employee, Vault)..."
                        maxlength="50"
                        pattern="[a-zA-Z0-9\s\-_+]+"
                        title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores, Plus"
                        oninput="this.value = this.value.replace(/[^a-zA-Z0-9\s\-_+]/g, '')">
                </div>
            </div>
        </div>
    </div>
    <!-- 2. QUICK ROLE-BASED ACCESS SUMMARY -->
    <div class="card border-0 shadow-sm rounded-3 mb-4">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex align-items-center mb-3">
                <i class="bi bi-person-badge text-primary fs-3 me-3"></i>
                <div>
                    <h5 class="fw-bold mb-0">Your Current Role: <span class="badge bg-primary fs-6 ms-1"><?php echo htmlspecialchars($userRole); ?></span></h5>
                    <small class="text-muted">Below is a breakdown of permissions per role in HR Vault 201.</small>
                </div>
            </div>
            <div class="row g-2 pt-2 text-center">
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-light">
                        <span class="badge bg-secondary mb-1">STAFF</span>
                        <div class="small text-muted">View own profile, request edits & submit documents</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-light">
                        <span class="badge bg-info text-dark mb-1">MANAGER</span>
                        <div class="small text-muted">View team records, manage reviews & generate contracts</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-light">
                        <span class="badge bg-success mb-1">HR</span>
                        <div class="small text-muted">Full 201 management, ATS recruitment & Analytics</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-2 border rounded bg-light">
                        <span class="badge bg-danger mb-1">ADMIN</span>
                        <div class="small text-muted">Full system access, approvals, settings & backups</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. SYSTEM MODULE CARDS (VISUAL GRAPHIC GRID) -->
    <h4 class="fw-bold mb-3"><i class="bi bi-grid-fill text-primary me-2"></i>Core System Features</h4>
    <div class="row g-4 mb-5" id="featureCardsContainer">

        <!-- Module 1: Employee Management -->
        <div class="col-md-6 col-lg-4 feature-item">
            <div class="card feature-card shadow-sm p-3">
                <div class="icon-box bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-people-fill"></i>
                </div>
                <h5 class="fw-bold mb-1">Employee 201 Records</h5>
                <p class="text-muted small mb-3">Centralized database for personal info, government numbers (SSS, TIN, PhilHealth, Pag-IBIG), education, and emergency contacts.</p>
                <div class="mt-auto border-top pt-2">
                    <span class="fw-bold small text-primary"><i class="bi bi-check-circle me-1"></i>How to Use:</span>
                    <ol class="small text-muted ps-3 mb-0 mt-1">
                        <li>Navigate to <strong>Employee List</strong>.</li>
                        <li>Click <strong>Add Employee</strong> to onboard new staff.</li>
                        <li>Click <strong>Edit/View Profile</strong> to update info or upload avatar.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Module 2: Analytics & Insights -->
        <div class="col-md-6 col-lg-4 feature-item">
            <div class="card feature-card shadow-sm p-3">
                <div class="icon-box bg-success bg-opacity-10 text-success">
                    <i class="bi bi-bar-chart-line-fill"></i>
                </div>
                <h5 class="fw-bold mb-1">Analytics Dashboard</h5>
                <p class="text-muted small mb-3">Real-time metrics on headcount, attrition trends, agency vs direct distribution, tenure, age breakdown, and birthday calendars.</p>
                <div class="mt-auto border-top pt-2">
                    <span class="fw-bold small text-success"><i class="bi bi-check-circle me-1"></i>How to Use:</span>
                    <ol class="small text-muted ps-3 mb-0 mt-1">
                        <li>Go to <strong>System Analytics</strong>.</li>
                        <li>Use top filters for Year, Department, or Group.</li>
                        <li>Click the <strong>Full Screen</strong> or <strong>Download</strong> icon on any chart to export images.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Module 3: Document Vault & Expiry -->
        <div class="col-md-6 col-lg-4 feature-item">
            <div class="card feature-card shadow-sm p-3">
                <div class="icon-box bg-warning bg-opacity-15 text-warning">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <h5 class="fw-bold mb-1">Encrypted Vault & Expiry</h5>
                <p class="text-muted small mb-3">AES-256 encrypted digital storage for contracts, NBI clearances, medical certificates, and ID cards with automated expiry tracking.</p>
                <div class="mt-auto border-top pt-2">
                    <span class="fw-bold small text-warning"><i class="bi bi-check-circle me-1"></i>How to Use:</span>
                    <ol class="small text-muted ps-3 mb-0 mt-1">
                        <li>Open employee profile ➔ <strong>Documents</strong>.</li>
                        <li>Upload PDF/Image with expiry date.</li>
                        <li>Monitor red warnings on Dashboard for expiring items.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Module 4: Contract & Document Generator -->
        <div class="col-md-6 col-lg-4 feature-item">
            <div class="card feature-card shadow-sm p-3">
                <div class="icon-box bg-info bg-opacity-10 text-info">
                    <i class="bi bi-file-earmark-word-fill"></i>
                </div>
                <h5 class="fw-bold mb-1">Contract Generator</h5>
                <p class="text-muted small mb-3">Automated generation of employment contracts, COE (Certificate of Employment), and custom templates using employee profile tags.</p>
                <div class="mt-auto border-top pt-2">
                    <span class="fw-bold small text-info"><i class="bi bi-check-circle me-1"></i>How to Use:</span>
                    <ol class="small text-muted ps-3 mb-0 mt-1">
                        <li>Select <strong>Generate Document</strong> or <strong>Bulk Contracts</strong>.</li>
                        <li>Choose template (e.g. Regularization Letter).</li>
                        <li>Preview auto-filled tags and download DOCX/PDF.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Module 5: Recruitment & Applicant Tracking -->
        <div class="col-md-6 col-lg-4 feature-item">
            <div class="card feature-card shadow-sm p-3">
                <div class="icon-box bg-purple bg-opacity-10 text-purple" style="color: #6f42c1; background: rgba(111,66,193,0.1);">
                    <i class="bi bi-briefcase-fill"></i>
                </div>
                <h5 class="fw-bold mb-1">Recruitment ATS Console</h5>
                <p class="text-muted small mb-3">Manage job postings, applicant resumes, interview scheduling, scoring matrix, and seamless 1-click hire onboarding into 201 records.</p>
                <div class="mt-auto border-top pt-2">
                    <span class="fw-bold small" style="color: #6f42c1;"><i class="bi bi-check-circle me-1"></i>How to Use:</span>
                    <ol class="small text-muted ps-3 mb-0 mt-1">
                        <li>Go to <strong>Recruitment / ATS</strong>.</li>
                        <li>Create a Job Opening or upload Applicant CVs.</li>
                        <li>Move applicants through pipeline stages to <strong>Hired</strong>.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Module 6: Performance & Disciplinary -->
        <div class="col-md-6 col-lg-4 feature-item">
            <div class="card feature-card shadow-sm p-3">
                <div class="icon-box bg-danger bg-opacity-10 text-danger">
                    <i class="bi bi-gavel"></i>
                </div>
                <h5 class="fw-bold mb-1">Disciplinary & Performance</h5>
                <p class="text-muted small mb-3">Track annual performance reviews, ratings, formal warnings, NTE (Notice to Explain), and disciplinary action history.</p>
                <div class="mt-auto border-top pt-2">
                    <span class="fw-bold small text-danger"><i class="bi bi-check-circle me-1"></i>How to Use:</span>
                    <ol class="small text-muted ps-3 mb-0 mt-1">
                        <li>Open employee profile ➔ <strong>Disciplinary / Reviews</strong>.</li>
                        <li>Log review score or issue formal warning record.</li>
                        <li>Track resolution status and employee explanations.</li>
                    </ol>
                </div>
            </div>
        </div>

    </div>

    <!-- 4. STEP-BY-STEP VISUAL WORKFLOW GUIDES -->
    <h4 class="fw-bold mb-3"><i class="bi bi-diagram-3-fill text-primary me-2"></i>Common Workflows & How-To Guides</h4>

    <div class="accordion shadow-sm mb-5" id="workflowAccordion">

        <!-- Workflow 1: Onboarding a New Employee -->
        <div class="accordion-item border-0 mb-2 rounded shadow-sm overflow-hidden">
            <h2 class="accordion-header">
                <button class="accordion-button fw-bold py-3" type="button" data-bs-toggle="collapse" data-bs-target="#wfOnboarding">
                    <i class="bi bi-person-plus-fill text-primary me-2 fs-5"></i> How to Add a New Employee
                </button>
            </h2>
            <div id="wfOnboarding" class="accordion-collapse collapse show" data-bs-parent="#workflowAccordion">
                <div class="accordion-body">
                    <div class="row g-3">
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light">
                                <span class="step-badge">1</span>
                                <h6 class="fw-bold mt-2 mb-1">Open Form</h6>
                                <p class="small text-muted mb-0">Click <strong>Add Employee</strong> in the top navigation bar.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light">
                                <span class="step-badge">2</span>
                                <h6 class="fw-bold mt-2 mb-1">Fill Profile</h6>
                                <p class="small text-muted mb-0">Enter Employee ID, Name, Department, Role, & Government IDs.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light">
                                <span class="step-badge">3</span>
                                <h6 class="fw-bold mt-2 mb-1">Upload Photo</h6>
                                <p class="small text-muted mb-0">(Optional) Attach a JPG/PNG avatar photo for the 201 profile.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light">
                                <span class="step-badge bg-success">4</span>
                                <h6 class="fw-bold mt-2 mb-1">Save & Confirm</h6>
                                <p class="small text-muted mb-0">Click <strong>Save Employee</strong>. Record is active immediately.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Workflow 2: Uploading & Approving 201 Documents -->
        <div class="accordion-item border-0 mb-2 rounded shadow-sm overflow-hidden">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed fw-bold py-3" type="button" data-bs-toggle="collapse" data-bs-target="#wfVault">
                    <i class="bi bi-file-earmark-lock-fill text-warning me-2 fs-5"></i> How Vault Document Upload & Approvals Work
                </button>
            </h2>
            <div id="wfVault" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                <div class="accordion-body">
                    <div class="row g-3">
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light">
                                <span class="step-badge">1</span>
                                <h6 class="fw-bold mt-2 mb-1">Upload Document</h6>
                                <p class="small text-muted mb-0">Employee or HR selects file (PDF/Image) & sets document category.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light">
                                <span class="step-badge">2</span>
                                <h6 class="fw-bold mt-2 mb-1">AES-256 Encryption</h6>
                                <p class="small text-muted mb-0">System automatically encrypts file to protected vault storage.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light">
                                <span class="step-badge">3</span>
                                <h6 class="fw-bold mt-2 mb-1">Approval Queue</h6>
                                <p class="small text-muted mb-0">Request enters <strong>Admin Approval Center</strong> for verification.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light">
                                <span class="step-badge bg-success">4</span>
                                <h6 class="fw-bold mt-2 mb-1">Approved & Linked</h6>
                                <p class="small text-muted mb-0">Admin approves ➔ File is securely accessible in 201 record.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Workflow 3: Generating Contracts & Official Documents -->
        <div class="accordion-item border-0 mb-2 rounded shadow-sm overflow-hidden">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed fw-bold py-3" type="button" data-bs-toggle="collapse" data-bs-target="#wfContract">
                    <i class="bi bi-file-earmark-word-fill text-info me-2 fs-5"></i> How to Generate Contracts & Official Documents
                </button>
            </h2>
            <div id="wfContract" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                <div class="accordion-body">
                    <div class="row g-3">
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-info">1</span>
                                <h6 class="fw-bold mt-2 mb-1">Select Generator</h6>
                                <p class="small text-muted mb-0">Go to <strong>Generate Document</strong> or <strong>Bulk Contracts</strong>.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-info">2</span>
                                <h6 class="fw-bold mt-2 mb-1">Pick Template</h6>
                                <p class="small text-muted mb-0">Choose COE, Regularization Letter, Contract, or custom template.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-info">3</span>
                                <h6 class="fw-bold mt-2 mb-1">Select Employee</h6>
                                <p class="small text-muted mb-0">System automatically fills tags like <code>{FIRST_NAME}</code> and <code>{SALARY}</code>.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-success">4</span>
                                <h6 class="fw-bold mt-2 mb-1">Download & Print</h6>
                                <p class="small text-muted mb-0">Click <strong>Generate DOCX/PDF</strong> to download ready-to-sign files.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Workflow 4: Recruitment & ATS Hiring -->
        <div class="accordion-item border-0 mb-2 rounded shadow-sm overflow-hidden">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed fw-bold py-3" type="button" data-bs-toggle="collapse" data-bs-target="#wfATS">
                    <i class="bi bi-briefcase-fill me-2 fs-5" style="color: #6f42c1;"></i> How ATS Recruitment & 1-Click Hiring Works
                </button>
            </h2>
            <div id="wfATS" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                <div class="accordion-body">
                    <div class="row g-3">
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge" style="background: #6f42c1;">1</span>
                                <h6 class="fw-bold mt-2 mb-1">Post Job Opening</h6>
                                <p class="small text-muted mb-0">Go to <strong>Recruitment / ATS</strong> ➔ Create a new job vacancy position.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge" style="background: #6f42c1;">2</span>
                                <h6 class="fw-bold mt-2 mb-1">Add Applicants</h6>
                                <p class="small text-muted mb-0">Upload resumes (PDF/DOCX) and candidate profile information.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge" style="background: #6f42c1;">3</span>
                                <h6 class="fw-bold mt-2 mb-1">Interview & Rate</h6>
                                <p class="small text-muted mb-0">Move candidates through pipeline stages (Applied ➔ Interviewed ➔ Offered).</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-success">4</span>
                                <h6 class="fw-bold mt-2 mb-1">1-Click Onboarding</h6>
                                <p class="small text-muted mb-0">Click <strong>Mark Hired</strong> to automatically create their active 201 profile.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Workflow 5: Disciplinary Actions & Performance Reviews -->
        <div class="accordion-item border-0 mb-2 rounded shadow-sm overflow-hidden">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed fw-bold py-3" type="button" data-bs-toggle="collapse" data-bs-target="#wfDisciplinary">
                    <i class="bi bi-gavel text-danger me-2 fs-5"></i> How Disciplinary & Performance Tracking Works
                </button>
            </h2>
            <div id="wfDisciplinary" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                <div class="accordion-body">
                    <div class="row g-3">
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-danger">1</span>
                                <h6 class="fw-bold mt-2 mb-1">Select Employee</h6>
                                <p class="small text-muted mb-0">Open employee profile ➔ Select <strong>Disciplinary / Reviews</strong> tab.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-danger">2</span>
                                <h6 class="fw-bold mt-2 mb-1">Log Case / Review</h6>
                                <p class="small text-muted mb-0">Issue performance score or formal Notice to Explain (NTE).</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-danger">3</span>
                                <h6 class="fw-bold mt-2 mb-1">Track Response</h6>
                                <p class="small text-muted mb-0">Attach employee written explanation and HR committee hearing notes.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-success">4</span>
                                <h6 class="fw-bold mt-2 mb-1">File Resolution</h6>
                                <p class="small text-muted mb-0">Set status to <strong>Resolved</strong> to permanently log entry in 201 history.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Workflow 6: Profile Edit Requests & Approvals -->
        <div class="accordion-item border-0 mb-2 rounded shadow-sm overflow-hidden">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed fw-bold py-3" type="button" data-bs-toggle="collapse" data-bs-target="#wfApprovals">
                    <i class="bi bi-check-square-fill text-success me-2 fs-5"></i> How Staff Profile Edits & Admin Approvals Work
                </button>
            </h2>
            <div id="wfApprovals" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                <div class="accordion-body">
                    <div class="row g-3">
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-success">1</span>
                                <h6 class="fw-bold mt-2 mb-1">Submit Edit</h6>
                                <p class="small text-muted mb-0">Staff updates profile info and clicks <strong>Submit Edit Request</strong>.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-success">2</span>
                                <h6 class="fw-bold mt-2 mb-1">Approval Queue</h6>
                                <p class="small text-muted mb-0">Request appears instantly in the <strong>Admin Approval Center</strong>.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-success">3</span>
                                <h6 class="fw-bold mt-2 mb-1">Side-by-Side Review</h6>
                                <p class="small text-muted mb-0">Admin compares old vs new profile data and notes.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-success">4</span>
                                <h6 class="fw-bold mt-2 mb-1">Auto-Update</h6>
                                <p class="small text-muted mb-0">Admin clicks <strong>Approve</strong> ➔ Employee 201 profile updates immediately.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Workflow 7: Exporting Master Lists & Analytics -->
        <div class="accordion-item border-0 mb-2 rounded shadow-sm overflow-hidden">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed fw-bold py-3" type="button" data-bs-toggle="collapse" data-bs-target="#wfExport">
                    <i class="bi bi-file-earmark-spreadsheet-fill text-primary me-2 fs-5"></i> How to Export Reports & Master Lists
                </button>
            </h2>
            <div id="wfExport" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                <div class="accordion-body">
                    <div class="row g-3">
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge">1</span>
                                <h6 class="fw-bold mt-2 mb-1">Set Filters</h6>
                                <p class="small text-muted mb-0">Filter by Department, Group, Year, or Active/Inactive status.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge">2</span>
                                <h6 class="fw-bold mt-2 mb-1">Choose Format</h6>
                                <p class="small text-muted mb-0">Click <strong>Export Master List (CSV)</strong> or <strong>Custom Print Report</strong>.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge">3</span>
                                <h6 class="fw-bold mt-2 mb-1">Customize Output</h6>
                                <p class="small text-muted mb-0">(For Reports) Check specific charts and matrices to include.</p>
                            </div>
                        </div>
                        <div class="col-md-3 text-center">
                            <div class="p-3 border rounded bg-light h-100">
                                <span class="step-badge bg-success">4</span>
                                <h6 class="fw-bold mt-2 mb-1">Save / Print</h6>
                                <p class="small text-muted mb-0">Download structured CSV or print a high-resolution PDF document.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Workflow 8: Disaster Recovery & Backups -->
        <div class="accordion-item border-0 rounded shadow-sm overflow-hidden">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed fw-bold py-3" type="button" data-bs-toggle="collapse" data-bs-target="#wfBackup">
                    <i class="bi bi-hdd-network-fill text-danger me-2 fs-5"></i> System Backups & Disaster Recovery (Admins Only)
                </button>
            </h2>
            <div id="wfBackup" class="accordion-collapse collapse" data-bs-parent="#workflowAccordion">
                <div class="accordion-body">
                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-info-circle-fill me-1"></i> Backups can be scheduled automatically or downloaded on-demand from <strong>Manage Users ➔ Disaster Recovery</strong>.
                    </div>
                    <ul class="small mb-0">
                        <li class="mb-2"><strong>Automated Backups:</strong> The system automatically streams daily database snapshots directly to disk.</li>
                        <li class="mb-2"><strong>Manual Backup:</strong> Go to <em>Disaster Recovery</em>, click <strong>Download Backup</strong>.</li>
                        <li class="mb-0"><strong>Restoration:</strong> Select your <code>.sql</code> backup archive in the Disaster Recovery panel to restore records seamlessly.</li>
                    </ul>
                </div>
            </div>
        </div>

    </div>

    <!-- 5. FAQ & TROUBLESHOOTING -->
    <h4 class="fw-bold mb-3"><i class="bi bi-question-circle-fill text-primary me-2"></i>Frequently Asked Questions</h4>
    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body p-4">
            <div class="row g-4">
                <div class="col-md-6">
                    <h6 class="fw-bold text-dark"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Why am I logged out automatically?</h6>
                    <p class="small text-muted mb-0">For security compliance, inactive sessions auto-logout after 15 minutes of inactivity. You can save your work periodically to prevent session timeouts.</p>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-bold text-dark"><i class="bi bi-shield-x text-danger me-2"></i>Why is my document upload failing?</h6>
                    <p class="small text-muted mb-0">Ensure your file is under 10MB and is a valid PDF, JPG, or PNG image. Executable or script files are strictly blocked by system security scanners.</p>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-bold text-dark"><i class="bi bi-pencil-square text-info me-2"></i>Can staff edit their own profile directly?</h6>
                    <p class="small text-muted mb-0">Staff can submit an <strong>Edit Profile Request</strong>. Once an Admin or HR approves the request in the Approval Center, the changes update automatically.</p>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-bold text-dark"><i class="bi bi-printer-fill text-success me-2"></i>How do I print or export analytics reports?</h6>
                    <p class="small text-muted mb-0">On the Analytics page, click <strong>Custom Report / Print</strong> at the top right to select which sections you want included in your PDF or printout.</p>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- INTERACTIVE LIVE SEARCH SCRIPT WITH VALIDATION & SANITIZATION -->
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const searchInput = document.getElementById('helpSearch');
        const featureItems = document.querySelectorAll('.feature-item');

        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                // Sanitize input in real-time
                let cleanVal = e.target.value.replace(/[^a-zA-Z0-9\s\-_+]/g, '');

                // Enforce max length limit
                if (cleanVal.length > 50) {
                    cleanVal = cleanVal.slice(0, 50);
                }

                e.target.value = cleanVal;
                const query = cleanVal.toLowerCase().trim();

                // Live filter feature cards
                featureItems.forEach(item => {
                    const text = item.textContent.toLowerCase();
                    if (query === '' || text.includes(query)) {
                        item.style.display = 'block';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        }
    });
</script>

<?php
// Include system footer if present in your file structure
if (file_exists(__DIR__ . '/footer.php')) {
    require 'footer.php';
} else {
    echo '</body></html>';
}
?>