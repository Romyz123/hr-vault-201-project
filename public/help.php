<?php
require '../src/Security.php';
session_start();

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>System User Manual</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
        }

        .help-section {
            margin-bottom: 2rem;
        }

        .step-list {
            list-style-type: none;
            padding-left: 0;
        }

        .step-list li {
            margin-bottom: 0.5rem;
            padding-left: 1.5rem;
            position: relative;
        }

        .step-list li::before {
            content: "\F26A";
            font-family: "bootstrap-icons";
            position: absolute;
            left: 0;
            color: #198754;
        }

        .card {
            border: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .icon-header {
            font-size: 2rem;
            margin-bottom: 1rem;
            display: block;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-dark bg-dark mb-4 shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">
                <i class="bi bi-arrow-left-circle me-2"></i> Back to Dashboard
            </a>
            <span class="navbar-text text-white">
                <i class="bi bi-book-half me-2"></i> User Manual & Help Guide
            </span>
        </div>
    </nav>

    <div class="container">
        <div class="row g-4">
            <!-- 1. Bulk Contract Printing -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 p-3">
                    <div class="card-body">
                        <i class="bi bi-printer-fill text-primary icon-header"></i>
                        <h5 class="card-title fw-bold">Bulk Contract Printing</h5>
                        <p class="card-text text-muted small">Generate contracts for multiple employees in one batch.</p>
                        <hr>
                        <ul class="step-list small">
                            <li>Go to <strong>Bulk Contract Print</strong> from the dashboard.</li>
                            <li>Use filters (Department, Hire Date) to find employees.</li>
                            <li>Select employees using the checkboxes.</li>
                            <li>Choose the <strong>Document Type</strong> (e.g., Project Contract).</li>
                            <li>Fill in details like Project Name and Dates.</li>
                            <li>Click <strong>Generate</strong> to create a printable PDF.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- 2. Bulk Employee Manager -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 p-3">
                    <div class="card-body">
                        <i class="bi bi-people-fill text-warning icon-header"></i>
                        <h5 class="card-title fw-bold">Bulk Employee Manager</h5>
                        <p class="card-text text-muted small">Mass update roles, departments, or statuses.</p>
                        <hr>
                        <ul class="step-list small">
                            <li>Go to <strong>Bulk Update Roles</strong>.</li>
                            <li>Select a <strong>Bulk Action</strong> (e.g., Update Status).</li>
                            <li>Choose the new value (e.g., Regular, Resigned).</li>
                            <li>Select the target employees.</li>
                            <li>Click <strong>Update Selected</strong> to apply changes instantly.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- 3. Disciplinary Console -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 p-3">
                    <div class="card-body">
                        <i class="bi bi-exclamation-triangle-fill text-danger icon-header"></i>
                        <h5 class="card-title fw-bold">Disciplinary Console</h5>
                        <p class="card-text text-muted small">Manage violations and generate legal notices.</p>
                        <hr>
                        <ul class="step-list small">
                            <li>Click <strong>File Case(s)</strong> to log a new violation.</li>
                            <li>You can select multiple employees for group offenses.</li>
                            <li>Click the <strong>NTE</strong> button to print a Notice to Explain.</li>
                            <li>Click the <strong>NOD</strong> button to print a Notice of Decision.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- 4. Digital 201 File -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 p-3">
                    <div class="card-body">
                        <i class="bi bi-folder2-open text-success icon-header"></i>
                        <h5 class="card-title fw-bold">Digital 201 File</h5>
                        <p class="card-text text-muted small">Manage employee documents and contracts.</p>
                        <hr>
                        <ul class="step-list small">
                            <li>Go to <strong>Edit Employee</strong> > <strong>Digital 201 File</strong> tab.</li>
                            <li>Click <strong>Upload New</strong> to add scanned files.</li>
                            <li>Use <strong>Generate Document</strong> to create contracts (Probationary, Regular, etc.).</li>
                            <li>Set expiry dates on uploads to get automatic alerts.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- 5. System Settings -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 p-3">
                    <div class="card-body">
                        <i class="bi bi-sliders text-dark icon-header"></i>
                        <h5 class="card-title fw-bold">System Settings</h5>
                        <p class="card-text text-muted small">Configure defaults and print layouts.</p>
                        <hr>
                        <ul class="step-list small">
                            <li>Go to <strong>Settings</strong> (Admin/Manager only).</li>
                            <li>Set <strong>Default Project Name</strong> for contracts.</li>
                            <li>Adjust <strong>Bulk Print Margins</strong> to fit your printer paper.</li>
                            <li>Toggle <strong>Maintenance Mode</strong> if needed.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- 6. Backup & Recovery -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 p-3">
                    <div class="card-body">
                        <i class="bi bi-hdd-network-fill text-info icon-header"></i>
                        <h5 class="card-title fw-bold">Backup & Recovery</h5>
                        <p class="card-text text-muted small">Protect your data.</p>
                        <hr>
                        <ul class="step-list small">
                            <li>Go to <strong>Manage Users</strong> > <strong>Disaster Recovery</strong>.</li>
                            <li>Click <strong>Download Backup</strong> to save the SQL database.</li>
                            <li>Click <strong>Save to Server</strong> to force a backup to the external drive.</li>
                            <li><strong>Restoring:</strong> Use the "Restore SQL" form for the database. For documents, manually extract the 'vault' folder from the ZIP to the server.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- 7. Video Tutorials -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 p-3">
                    <div class="card-body">
                        <i class="bi bi-play-btn-fill text-danger icon-header"></i>
                        <h5 class="card-title fw-bold">Video Tutorials</h5>
                        <p class="card-text text-muted small">Watch step-by-step guides.</p>
                        <hr>
                        <div class="alert alert-light border text-center">
                            <i class="bi bi-camera-video fs-1 text-muted"></i>
                            <p class="mb-0 small text-muted">Tutorial videos will be uploaded here.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="alert alert-secondary mt-5 text-center">
            <i class="bi bi-info-circle me-2"></i> For technical support or system errors, please contact the IT Department.
        </div>
    </div>
</body>

</html>