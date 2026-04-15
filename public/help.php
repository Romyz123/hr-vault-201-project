<?php
// ======================================================
// [FILE] public/help.php
// [PURPOSE] System Instructions and User Manual
// ======================================================

require '../config/db.php';
require '../src/Security.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// 1. SECURITY: Require Login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// [UX] Fetch Client Timeout
$clientTimeout = 900;
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
    $val = $stmt->fetchColumn();
    if ($val) $clientTimeout = (int)$val;
} catch (Exception $e) {
}
?>
<?php require 'header.php'; ?>

<div class="container pb-5" style="max-width: 900px;">

    <div class="text-center mb-4">
        <h2 class="fw-bold"><i class="bi bi-book-half text-primary"></i> TESP HR 201 System</h2>
        <p class="text-muted">Documentation, Architecture, and Deployment Guide</p>
    </div>

    <div class="accordion shadow-sm" id="manualAccordion">

        <!-- 1. System Overview -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOverview">
                    <strong>1. System Overview & Features</strong>
                </button>
            </h2>
            <div id="collapseOverview" class="accordion-collapse collapse show" data-bs-parent="#manualAccordion">
                <div class="accordion-body">
                    <p>The <strong>TESP HR 201 System</strong> is a secure, web-based Human Resource Information System (HRIS) designed for managing employee records, digital 201 files, recruitment, and performance reviews.</p>

                    <h6 class="fw-bold text-primary mt-3">Key Features:</h6>
                    <ul>
                        <li><strong>Security:</strong> AES-256 encryption for sensitive documents, Role-Based Access Control (RBAC), and Audit Logging.</li>
                        <li><strong>Compliance:</strong> Designed strictly to meet MHI Security Application Requirements.</li>
                        <li><strong>Modules:</strong> Employee Management, Recruitment & ATS, Disciplinary Console, Performance Reviews, Document Expiry Tracking.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- 2. Architecture -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseArch">
                    <strong>2. Architecture & Data Flow</strong>
                </button>
            </h2>
            <div id="collapseArch" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                <div class="accordion-body">
                    <h6 class="fw-bold text-primary">Core Components</h6>
                    <ul>
                        <li><code>public/</code> - Web entry points handling user interactions, file uploads, and viewing.</li>
                        <li><code>config/</code> - Database connection (<code>db.php</code>) and configuration (<code>config.php</code>).</li>
                        <li><code>src/Security.php</code> - Centralized security utilities for CSRF, rate limiting, and input sanitization.</li>
                        <li><code>vault/</code> - Protected directory storing actual PDF files with randomized UUID names.</li>
                    </ul>

                    <h6 class="fw-bold text-primary mt-3">Critical Data Flow: Document Upload</h6>
                    <ol>
                        <li><strong>Form Submission:</strong> User selects employee, category, and file; CSRF token is embedded.</li>
                        <li><strong>Validation:</strong> Enforces Strict MIME type validation (PDF/Images only) using <code>finfo</code>.</li>
                        <li><strong>Storage:</strong> Files move to Vault with UUID naming; metadata stored in <code>requests</code> table.</li>
                        <li><strong>Approval:</strong> Admin approves via the Approval Center, moving the file into the active <code>documents</code> table.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- 3. Security Patterns -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseSecurity">
                    <strong>3. Security Patterns & Compliance</strong>
                </button>
            </h2>
            <div id="collapseSecurity" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                <div class="accordion-body">
                    <div class="alert alert-warning small">
                        <i class="bi bi-shield-lock-fill"></i> <strong>MHI Compliance Note:</strong> These patterns must be strictly followed when making modifications.
                    </div>

                    <h6 class="fw-bold text-primary mt-3">CSRF Protection</h6>
                    <p class="small">Every form includes a hidden <code>csrf_token</code>. POST requests are validated with <code>$security->checkCSRF()</code>. Tokens regenerate per session.</p>

                    <h6 class="fw-bold text-primary mt-3">Input Sanitization</h6>
                    <p class="small">Global sanitization neutralizes XSS and Null-Byte injections. Use parametrized PDO queries exclusively (<code>$pdo->prepare()</code> + <code>execute([])</code>).</p>

                    <h6 class="fw-bold text-primary mt-3">Rate Limiting</h6>
                    <p class="small">Sensitive endpoints enforce a strict limit of 60 requests per 60 seconds per IP to prevent brute force attacks.</p>

                    <h6 class="fw-bold text-primary mt-3">File Security</h6>
                    <ul class="small mb-0">
                        <li><strong>MIME Checking:</strong> File extensions are untrusted. True MIME types are verified server-side.</li>
                        <li><strong>Malware Block:</strong> Deep Signature Scans reject files containing <code>MZ</code>, <code>ELF</code>, or <code>&lt;?php</code> headers.</li>
                        <li><strong>Path Validation:</strong> Uses <code>realpath()</code> to prevent directory traversal attacks.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- 4. Deployment & Requirements -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDeploy">
                    <strong>4. Server Requirements & Deployment</strong>
                </button>
            </h2>
            <div id="collapseDeploy" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                <div class="accordion-body">
                    <h6 class="fw-bold text-primary">Server Requirements</h6>
                    <ul class="small">
                        <li><strong>OS:</strong> Windows Server (IIS/Apache) or Linux.</li>
                        <li><strong>Web Server:</strong> Apache 2.4+ (with <code>mod_rewrite</code>).</li>
                        <li><strong>PHP:</strong> v8.0+ (Extensions: pdo_mysql, openssl, mbstring, gd, zip, fileinfo).</li>
                        <li><strong>Database:</strong> MySQL 5.7+ or MariaDB 10.4+.</li>
                        <li><strong>SSL:</strong> A valid SSL Certificate is mandatory for production.</li>
                    </ul>

                    <h6 class="fw-bold text-primary mt-3">Configuration Checklist</h6>
                    <ol class="small">
                        <li>Create a strong, 32-character string for <code>VAULT_KEY</code> inside <code>config/config.php</code>.</li>
                        <li>Ensure the web server user has Write access to <code>vault/</code>, <code>public/uploads/</code>, and <code>backups/</code>.</li>
                        <li>Disable debugging in <code>config/db.php</code> (Set <code>display_errors = 0</code>).</li>
                        <li>Delete all development files via the red warning prompt on the Admin Dashboard.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- 5. Troubleshooting -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTrouble">
                    <strong>5. Troubleshooting & Maintenance</strong>
                </button>
            </h2>
            <div id="collapseTrouble" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                <div class="accordion-body small">
                    <h6 class="fw-bold text-danger">"Database connection error"</h6>
                    <p>Check <code>config/config.php</code> credentials. Verify if MySQL is using Port 3306 or 3307.</p>

                    <h6 class="fw-bold text-danger">"Decryption Failed" / Files not loading</h6>
                    <p>Ensure the <code>VAULT_KEY</code> in <code>config.php</code> matches the key originally used to encrypt the files. <strong>Do not change this key</strong> once files are actively stored in the vault.</p>

                    <h6 class="fw-bold text-danger">"Upload Failed" or 0 Byte Files</h6>
                    <p>Check folder permissions for the <code>vault/</code> directory. Ensure <code>upload_max_filesize</code> and <code>post_max_size</code> are appropriately set in <code>php.ini</code>.</p>

                    <hr>

                    <h6 class="fw-bold text-primary">Automated Backups</h6>
                    <p>Backups can be automated via Windows Task Scheduler or Linux Cron using the script at <code>public/cron_backup.php</code>. The script is heavily optimized to stream data directly to the disk, bypassing memory limits.</p>
                </div>
            </div>
        </div>

        <!-- 6. MHI Disaster Recovery -->
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed bg-danger text-white bg-opacity-75" type="button" data-bs-toggle="collapse" data-bs-target="#collapseMHI">
                    <strong>6. Disaster Recovery SOP (Massive Data / No CMD)</strong>
                </button>
            </h2>
            <div id="collapseMHI" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                <div class="accordion-body small">
                    <p>Because MHI policy prohibits Command Line (CMD) scripts, and PHP web scripts will time out when processing a massive 20GB+ Vault, follow this procedure to safely Backup and Restore the system.</p>

                    <h6 class="fw-bold text-danger mt-3">PHASE 1: BACKUP PROCEDURE</h6>
                    <ol>
                        <li><strong>Database:</strong> Go to <em>Manager Users -> Disaster Recovery</em>. Click <strong>Download Backup</strong>. (Leave "Include Vault Files" unchecked). This safely streams your SQL data and Encryption Key without overloading PHP RAM.</li>
                        <li><strong>Multi-Part ZIPs:</strong> If your automated backup exceeded the GB limit, the system created multiple files (e.g., <code>Part1.zip</code>, <code>Part2.zip</code>). Collect all parts.</li>
                        <li><strong>Vault Files (Manual Shortcut):</strong> If skipping the web-backup, open Windows File Explorer on the server, navigate to <code>htdocs\hr-vault\vault\</code>, and manually copy it to an external drive.</li>
                    </ol>

                    <h6 class="fw-bold text-success mt-3">PHASE 2: RESTORE PROCEDURE</h6>
                    <ol>
                        <li><strong>Vault Files:</strong> Extract ALL your backup ZIP parts. Merge all the extracted <code>vault</code> folders together, and place them back into the <code>htdocs\hr-vault\</code> directory on the new server.</li>
                        <li><strong>Database (Web UI):</strong> Go to <em>Manage Users -> Disaster Recovery</em>. Use the <strong>Restore SQL</strong> tool. You can hold CTRL/CMD and select ALL parts at once. The system will automatically stitch them together.</li>
                        <li><strong>Database (GUI Client - Massive Restores without CMD):</strong> If your database is so massive that it exceeds browser capabilities, and Command Line is forbidden by MHI policy, use a standard Database GUI tool (like MySQL Workbench, HeidiSQL, or DBeaver).
                            <ul>
                                <li>Open your Database GUI and connect to the server (<code>127.0.0.1</code>, Port <code>3306</code>) using your database administrator credentials.</li>
                                <li>Select the <code>hr201_prod</code> database. Go to <strong>File > Run SQL Script...</strong> (or <strong>Import > Load SQL File</strong>).</li>
                                <li>Select your extracted <code>.sql</code> backup parts one by one and execute them. This bypasses all browser limits instantly and securely.</li>
                            </ul>
                        </li>
                        <li><strong>Alignment:</strong> Go to <em>System Recovery Console -> Orphaned Files</em> and run a <strong>Master Sync</strong>. This instantly aligns the database records with the physical files you just copied over.</li>
                    </ol>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="assets/bootstrap.bundle.min.js"></script>
</body>

</html>