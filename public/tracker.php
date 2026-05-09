<?php
// ======================================================
// [FILE] public/tracker.php
// [STATUS] Phase 3: Missing Document Tracker (Visual)
// ======================================================

// ---------- 1) CONFIGURATION & ACCESS CONTROL ----------
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
require '../src/Validator.php';
require '../src/SearchHelper.php';
require 'options.php';

// [FIX] Ensure checkSessionTimeout is defined before calling it
if (!function_exists('checkSessionTimeout')) {
    require_once __DIR__ . '/../config/db.php';
}
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

$logger = new Logger($pdo);
// [UX] Fetch Client Timeout
$clientTimeout = 900;
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
    $val = $stmt->fetchColumn();
    if ($val) $clientTimeout = (int)$val;
} catch (Exception $e) {
}

// 1. SECURITY
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

// 2. CONFIGURATION
// Define the "Mandatory" categories you want to track
$REQUIRED_DOCS = [
    '201 Files'    => ['201', 'PDS', 'Data Sheet', 'Resume'], // Keywords to match
    'Valid ID'     => ['ID', 'Passport', 'License', 'SSS', 'PhilHealth'],
    'Contract'     => ['Contract', 'Appointment', 'Offer'],
    'Medical'      => ['Medical', 'Fit to Work', 'Exam'],
    'Clearance'    => ['NBI', 'Police', 'Barangay']
];

// [SECURITY] Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ---------- 2) ACTION HANDLERS (CRUD) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR', 'STAFF'])) {
    // [SECURITY] Verify CSRF Token
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die(json_encode(['status' => 'error', 'message' => 'Invalid CSRF Token']));
    }

    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_req') {
            // [SECURITY] Staff cannot manage requirements
            if ($_SESSION['role'] === 'STAFF') {
                header("Location: tracker.php?error=" . urlencode("Access Denied."));
                exit;
            }
            try {
                $name = trim($_POST['req_name']);
                $keys = trim($_POST['req_keywords']);

                // [SECURITY] Validation
                if (strlen($name) > 100 || !preg_match('/^[a-zA-Z0-9\s\-\(\)\.]+$/', $name)) {
                    header("Location: tracker.php?error=" . urlencode("Invalid Requirement Name (Max 100 chars, Alphanumeric, dots, parens only)."));
                    exit;
                }
                if (strlen($keys) > 255 || !preg_match('/^[a-zA-Z0-9\s\-\,\.]+$/', $keys)) {
                    header("Location: tracker.php?error=" . urlencode("Invalid Keywords (Max 255 chars, Alphanumeric & commas only)."));
                    exit;
                }

                if ($name && $keys) {
                    // [NEW] Duplicate Block: Check if name exists
                    $chk = $pdo->prepare("SELECT id FROM document_requirements WHERE name = ?");
                    $chk->execute([$name]);
                    if ($chk->rowCount() > 0) {
                        header("Location: tracker.php?error=" . urlencode("Requirement '$name' already exists."));
                        exit;
                    }
                    $pdo->prepare("INSERT INTO document_requirements (name, keywords) VALUES (?, ?)")->execute([$name, $keys]);
                }
                $logger->log($_SESSION['user_id'], 'ADD_REQUIREMENT', "Added document requirement: $name ($keys)");
            } catch (PDOException $e) { /* Ignore if table missing */
            }
        } elseif ($_POST['action'] === 'delete_req') {
            // [SECURITY] Staff cannot manage requirements
            if ($_SESSION['role'] === 'STAFF') {
                header("Location: tracker.php?error=" . urlencode("Access Denied."));
                exit;
            }
            try {
                $id = $_POST['req_id'];

                // Fetch name before deleting so we can clean up exemptions
                $oldNameStmt = $pdo->prepare("SELECT name FROM document_requirements WHERE id = ?");
                $oldNameStmt->execute([$id]);
                $oldName = $oldNameStmt->fetchColumn();

                // Delete the requirement
                $pdo->prepare("DELETE FROM document_requirements WHERE id = ?")->execute([$id]);

                // Clean up any N/A exemptions linked to this deleted requirement
                if ($oldName) {
                    $pdo->prepare("DELETE FROM document_exemptions WHERE requirement_name = ?")->execute([$oldName]);
                }

                $logger->log($_SESSION['user_id'], 'DELETE_REQUIREMENT', "Deleted document requirement ID: $id");
                header("Location: tracker.php?msg=" . urlencode("✅ Requirement deleted successfully."));
                exit;
            } catch (PDOException $e) {
                header("Location: tracker.php?error=" . urlencode("❌ Error deleting requirement."));
                exit;
            }
        } elseif ($_POST['action'] === 'edit_req') {
            // [SECURITY] Staff cannot manage requirements
            if ($_SESSION['role'] === 'STAFF') {
                header("Location: tracker.php?error=" . urlencode("Access Denied."));
                exit;
            }
            try {
                $id = $_POST['req_id'];
                $name = trim($_POST['req_name']);
                $keys = trim($_POST['req_keywords']);

                if (strlen($name) > 100 || !preg_match('/^[a-zA-Z0-9\s\-\(\)\.]+$/', $name)) {
                    header("Location: tracker.php?error=" . urlencode("Invalid Requirement Name."));
                    exit;
                }

                if ($name && $keys) {
                    // Fetch old name to update exemptions if the name changed
                    $oldNameStmt = $pdo->prepare("SELECT name FROM document_requirements WHERE id = ?");
                    $oldNameStmt->execute([$id]);
                    $oldName = $oldNameStmt->fetchColumn();

                    $pdo->prepare("UPDATE document_requirements SET name = ?, keywords = ? WHERE id = ?")->execute([$name, $keys, $id]);

                    // Keep exemptions synced with the new name
                    if ($oldName && $oldName !== $name) {
                        $pdo->prepare("UPDATE document_exemptions SET requirement_name = ? WHERE requirement_name = ?")->execute([$name, $oldName]);
                    }

                    $logger->log($_SESSION['user_id'], 'EDIT_REQUIREMENT', "Edited document requirement ID: $id to $name");
                    header("Location: tracker.php?msg=" . urlencode("✅ Requirement updated successfully."));
                    exit;
                }
            } catch (PDOException $e) {
            }
        } elseif ($_POST['action'] === 'apply_quick_fix') {
            // [SECURITY] Staff cannot manage requirements
            if ($_SESSION['role'] === 'STAFF') {
                header("Location: tracker.php?error=" . urlencode("Access Denied."));
                exit;
            }

            $fixDocs = $_POST['fix_docs'] ?? []; // Array of doc_id => selected_category

            if (empty($fixDocs)) {
                header("Location: tracker.php?error=" . urlencode("No documents selected for quick fix."));
                exit;
            }

            $allowedCategories = array_keys($REQUIRED_DOCS);
            try {
                $catStmt = $pdo->query("SELECT name FROM document_requirements");
                while ($r = $catStmt->fetch(PDO::FETCH_ASSOC)) {
                    $allowedCategories[] = $r['name'];
                }
            } catch (Exception $e) {
                // Use default categories if table is unavailable
            }
            $allowedCategories = array_unique($allowedCategories);

            $updatedCount = 0;
            $updatedIds = [];
            $skippedIds = [];
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE documents SET category = ?, updated_at = NOW() WHERE id = ?");
                foreach ($fixDocs as $docId => $newCategory) {
                    $docId = (int)$docId;
                    $newCategory = trim($newCategory);
                    if ($docId <= 0 || $newCategory === '') {
                        continue;
                    }
                    if (!in_array($newCategory, $allowedCategories, true)) {
                        $skippedIds[] = $docId;
                        continue;
                    }

                    $stmt->execute([$newCategory, $docId]);
                    if ($stmt->rowCount() > 0) {
                        $updatedCount++;
                        $updatedIds[] = $docId;
                    }
                }
                $pdo->commit();
                $updatedIdsCsv = $updatedIds ? implode(',', $updatedIds) : 'none';
                $logger->log($_SESSION['user_id'], 'QUICK_FIX_DOCUMENTS', "Applied quick fix to $updatedCount documents. IDs: $updatedIdsCsv.");
                $redirectMsg = "✅ Successfully re-categorized $updatedCount documents.";
                if (!empty($skippedIds)) {
                    $redirectMsg .= " Skipped invalid category updates for IDs: " . implode(',', array_unique($skippedIds)) . ".";
                }
                header("Location: tracker.php?msg=" . urlencode($redirectMsg));
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                header("Location: tracker.php?error=" . urlencode("❌ Error: " . $e->getMessage()));
            }
            exit;
        } elseif ($_POST['action'] === 'send_reminder') {
            // [NEW] Handle Email Reminder Logic
            $empId = $_POST['emp_id'];
            $reqs  = $_POST['reqs'] ?? []; // Array of selected missing items

            // Fetch Employee Email
            // [FIX] Use DB time difference to avoid Timezone issues (PHP time vs MySQL NOW)
            $stmt = $pdo->prepare("SELECT first_name, email, TIMESTAMPDIFF(SECOND, last_reminded, NOW()) as seconds_since FROM employees WHERE emp_id = ?");
            $stmt->execute([$empId]);
            $emp = $stmt->fetch();

            if (is_array($emp) && !empty($emp['email'])) {
                // [SPAM PROTECTION] Limit to 1 email per 24 hours (86400 seconds)
                if ($emp['seconds_since'] !== null && $emp['seconds_since'] < 86400) {
                    header("Location: tracker.php?error=" . urlencode("⏳ Please wait 24 hours before sending another reminder to " . $emp['first_name']));
                    exit;
                }

                // [MHI POLICY] Emails disabled. Just update the timestamp for tracking manual reminders.
                $pdo->prepare("UPDATE employees SET last_reminded = NOW() WHERE emp_id = ?")->execute([$empId]);
                $logger->log($_SESSION['user_id'], 'SENT_REMINDER', "Sent manual reminder to employee ID: $empId");
                header("Location: tracker.php?msg=" . urlencode("✅ Marked as reminded manually for " . $emp['first_name']));
                exit;
            }
            header("Location: tracker.php?error=" . urlencode("❌ Cannot mark reminder (No email on record for this employee)."));
            exit;
        } elseif ($_POST['action'] === 'ajax_send_reminder') {
            // [NEW] AJAX Handler for Bulk Progress Bar
            header('Content-Type: application/json');
            $empId = $_POST['emp_id'];

            // 1. Fetch Employee
            $stmt = $pdo->prepare("SELECT first_name, email, TIMESTAMPDIFF(SECOND, last_reminded, NOW()) as seconds_since FROM employees WHERE emp_id = ?");
            $stmt->execute([$empId]);
            $emp = $stmt->fetch();

            if (!$emp || empty($emp['email'])) {
                echo json_encode(['status' => 'skipped', 'message' => 'No email']);
                exit;
            }

            // 2. Cooldown Check (24h)
            if ($emp['seconds_since'] !== null && $emp['seconds_since'] < 86400) {
                echo json_encode(['status' => 'skipped', 'message' => 'Cooldown active']);
                exit;
            }

            // 3. Calculate Missing Items
            $reqs = [];
            try {
                $stmt = $pdo->query("SELECT * FROM document_requirements");
                while ($r = $stmt->fetch()) {
                    $reqs[$r['name']] = array_map('trim', explode(',', $r['keywords']));
                }
            } catch (Exception $e) {
            }
            if (empty($reqs)) $reqs = ['201 Files' => ['201'], 'Valid ID' => ['ID'], 'Contract' => ['Contract']];

            $missing = [];
            $docs = $pdo->prepare("SELECT category, original_name FROM documents WHERE employee_id = ? AND deleted_at IS NULL");
            $docs->execute([$empId]);
            $empDocs = $docs->fetchAll();

            $exempt = $pdo->prepare("SELECT requirement_name FROM document_exemptions WHERE employee_id = ?");
            $exempt->execute([$empId]);
            $empExempt = $exempt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($reqs as $cat => $keys) {
                if (in_array($cat, $empExempt)) continue;
                $found = false;
                foreach ($empDocs as $d) {
                    if (stripos($d['category'], $cat) !== false) {
                        $found = true;
                        break;
                    }
                    foreach ($keys as $k) {
                        if (stripos($d['original_name'], $k) !== false || stripos($d['category'], $k) !== false) {
                            $found = true;
                            break;
                        }
                    }
                }
                if (!$found) $missing[] = $cat;
            }

            if (empty($missing)) {
                echo json_encode(['status' => 'skipped', 'message' => 'No missing docs']);
                $logger->log($_SESSION['user_id'], 'SKIPPED_REMINDER', "Skipped reminder for employee ID: $empId (no missing docs)");
                exit;
            }

            // 4. Send Email (With Logo)
            $listHtml = "<ul>";
            foreach ($missing as $m) $listHtml .= "<li>" . htmlspecialchars($m) . "</li>";
            $listHtml .= "</ul>";

            // [LOGO LOGIC]
            $logoPath = __DIR__ . '/assets/images/tesp-logo-1.png';
            $logoHtml = file_exists($logoPath) ? '<img src="data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) . '" style="width:100px; display:block; margin-bottom:15px;">' : '<h2>TES PHILIPPINES</h2>';

            $to = $emp['email'];
            $subject = "Action Required: Missing Documents - HR 201 File";
            $body = "<html><body style='font-family: Arial, sans-serif; color: #333;'>
                    <div style='text-align:center;'>$logoHtml</div>
                    <p>Dear " . htmlspecialchars($emp['first_name']) . ",</p>
                    <p>This is a gentle reminder regarding your 201 File requirements. Our records indicate the following are still pending:</p>
                    $listHtml
                    <p>Please submit them as soon as possible.</p>
                    <br><p>Thank you,<br><strong>Human Resources</strong></p></body></html>";
            $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: HR System <no-reply@hrsystem.com>\r\n";

            if (mail($to, $subject, $body, $headers)) {
                $pdo->prepare("UPDATE employees SET last_reminded = NOW() WHERE emp_id = ?")->execute([$empId]);
                $logger->log($_SESSION['user_id'], 'SENT_AJAX_REMINDER', "Sent AJAX reminder to employee ID: $empId");
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Mail failed']);
            }
            exit;
        } elseif ($_POST['action'] === 'toggle_exempt') {
            // [NEW] Handle N/A Toggle (Exemptions)
            $empId = $_POST['emp_id'];
            $reqName = $_POST['req_name'];

            // Ensure table exists (Auto-fix)
            $pdo->exec("CREATE TABLE IF NOT EXISTS document_exemptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id VARCHAR(50) NOT NULL,
                requirement_name VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_exemption (employee_id, requirement_name)
            )");

            // Check if currently exempt
            $stmt = $pdo->prepare("SELECT id FROM document_exemptions WHERE employee_id = ? AND requirement_name = ?");
            $stmt->execute([$empId, $reqName]);

            if ($stmt->fetch()) {
                $pdo->prepare("DELETE FROM document_exemptions WHERE employee_id = ? AND requirement_name = ?")->execute([$empId, $reqName]);
                $logger->log($_SESSION['user_id'], 'TOGGLE_EXEMPTION', "Removed exemption for employee ID: $empId, requirement: $reqName");
            } else {
                $pdo->prepare("INSERT INTO document_exemptions (employee_id, requirement_name) VALUES (?, ?)")->execute([$empId, $reqName]);
                $logger->log($_SESSION['user_id'], 'TOGGLE_EXEMPTION', "Added exemption for employee ID: $empId, requirement: $reqName");
            }
            $redirectTo = !empty($_POST['redirect_query']) ? "tracker.php?" . $_POST['redirect_query'] : "tracker.php";
            header("Location: " . $redirectTo);
            exit;
        } elseif ($_POST['action'] === 'bulk_reminders') {
            // [NEW] Bulk Reminder Handler
            $empIds = $_POST['emp_ids'] ?? [];
            $sent = 0;
            $skipped = 0;

            // 1. Fetch Requirements (Quick Fetch for Loop)
            $reqs = [];
            try {
                $stmt = $pdo->query("SELECT * FROM document_requirements");
                while ($r = $stmt->fetch()) {
                    $reqs[$r['name']] = array_map('trim', explode(',', $r['keywords']));
                }
            } catch (Exception $e) {
            }
            if (empty($reqs)) $reqs = ['201 Files' => ['201'], 'Valid ID' => ['ID'], 'Contract' => ['Contract']];

            // 2. Process Each Employee
            foreach ($empIds as $eid) {
                // Check cooldown & email
                $stmt = $pdo->prepare("SELECT first_name, email, TIMESTAMPDIFF(SECOND, last_reminded, NOW()) as seconds_since FROM employees WHERE emp_id = ?");
                $stmt->execute([$eid]);
                $emp = $stmt->fetch();

                if ($emp && !empty($emp['email'])) {
                    // Skip if sent within 24 hours
                    if ($emp['seconds_since'] !== null && $emp['seconds_since'] < 86400) {
                        $skipped++;
                        continue;
                    }

                    // Calculate missing items for this employee
                    $missing = [];
                    $docs = $pdo->prepare("SELECT category, original_name FROM documents WHERE employee_id = ? AND deleted_at IS NULL");
                    $docs->execute([$eid]);
                    $empDocs = $docs->fetchAll();

                    $exempt = $pdo->prepare("SELECT requirement_name FROM document_exemptions WHERE employee_id = ?");
                    $exempt->execute([$eid]);
                    $empExempt = $exempt->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($reqs as $cat => $keys) {
                        if (in_array($cat, $empExempt)) continue;
                        $found = false;
                        foreach ($empDocs as $d) {
                            if (stripos($d['category'], $cat) !== false) {
                                $found = true;
                                break;
                            }
                            foreach ($keys as $k) {
                                if (stripos($d['original_name'], $k) !== false || stripos($d['category'], $k) !== false) {
                                    $found = true;
                                    break;
                                }
                            }
                        }
                        if (!$found) $missing[] = $cat;
                    }

                    if (!empty($missing)) {
                        // [MHI POLICY] Email disabled. Just update timestamp.
                        $pdo->prepare("UPDATE employees SET last_reminded = NOW() WHERE emp_id = ?")->execute([$eid]);
                        $logger->log($_SESSION['user_id'], 'BULK_REMINDER', "Logged manual bulk reminder for $eid");
                        $sent++;
                    }
                }
            }
            // [FIX] Clean redirect to prevent form resubmission on refresh
            header("Location: tracker.php?msg=" . urlencode("✅ Bulk Action: Sent $sent reminders. Skipped $skipped (cooldown/no email)."));
            exit;
        } elseif ($_POST['action'] === 'bulk_move') {
            // [NEW] Bulk Move / Re-categorize
            $docIds = $_POST['doc_ids'] ?? [];
            $newCat = $_POST['new_category'];
            $targetEmp = trim($_POST['target_emp_id'] ?? '');

            if (empty($docIds) || empty($newCat)) {
                header("Location: tracker.php?report=misclassified&error=" . urlencode("Please select documents and a category."));
                exit;
            }

            // Validate Target Employee if provided
            if (!empty($targetEmp)) {
                $chk = $pdo->prepare("SELECT id FROM employees WHERE emp_id = ?");
                $chk->execute([$targetEmp]);
                if (!$chk->fetch()) {
                    header("Location: tracker.php?report=misclassified&error=" . urlencode("Target Employee ID not found."));
                    exit;
                }
            }

            $placeholders = implode(',', array_fill(0, count($docIds), '?'));
            $sql = "UPDATE documents SET category = ?, updated_at = NOW()";
            $params = [$newCat];

            if (!empty($targetEmp)) {
                $sql .= ", employee_id = ?";
                $params[] = $targetEmp;
            }

            $sql .= " WHERE id IN ($placeholders)";
            $params = array_merge($params, $docIds);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $count = $stmt->rowCount();

            header("Location: tracker.php?report=misclassified&msg=" . urlencode("✅ Moved/Updated $count documents."));
            $logger->log($_SESSION['user_id'], 'BULK_MOVE_DOCUMENTS', "Bulk moved/updated $count documents. New category: $newCat, Target Emp: $targetEmp");
            exit;
        } elseif ($_POST['action'] === 'rename_file') {
            // [NEW] Rename File
            $docId = $_POST['doc_id'];
            $newName = trim($_POST['new_name']);

            if ($docId && $newName) {
                // [SECURITY] Validate Filename Characters
                if (!preg_match('/^[a-zA-Z0-9\s\-\.\(\)_]+$/', $newName)) {
                    header("Location: tracker.php?report=misclassified&error=" . urlencode("❌ Invalid filename. Allowed: Alphanumeric, Spaces, Dots, Dashes, Underscores, Parentheses."));
                    exit;
                }
                if (strlen($newName) > 100) {
                    header("Location: tracker.php?report=misclassified&error=" . urlencode("❌ Filename too long (Max 100 chars)."));
                    exit;
                }

                // [FIX] Preserve file extension to ensure format isn't lost
                $stmt = $pdo->prepare("SELECT original_name, category, employee_id FROM documents WHERE id = ?");
                $stmt->execute([$docId]);
                $currentDoc = $stmt->fetch();

                if ($currentDoc) {
                    $info = pathinfo($currentDoc['original_name']);
                    $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
                    if ($ext !== '' && (strlen($newName) < strlen($ext) || substr_compare($newName, $ext, -strlen($ext), strlen($ext), true) !== 0)) {
                        $newName .= $ext;
                    }

                    // [STAFF WORKFLOW] Create a request
                    if ($_SESSION['role'] === 'STAFF') {
                        $payload = [
                            'new_name' => $newName,
                            'original_details' => $currentDoc
                        ];
                        $pdo->prepare("INSERT INTO requests (user_id, request_type, target_id, json_payload) VALUES (?, 'EDIT_DOC', ?, ?)") // $payload is an array
                            ->execute([$_SESSION['user_id'], $docId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);

                        header("Location: tracker.php?report=misclassified&msg=" . urlencode("✅ Rename request submitted for approval."));
                        $logger->log($_SESSION['user_id'], 'REQUEST_RENAME_DOC', "Requested rename for document ID: $docId to $newName");
                        exit;
                    }

                    // [ADMIN/HR/MANAGER WORKFLOW] Direct update
                    $pdo->prepare("UPDATE documents SET original_name = ?, updated_at = NOW() WHERE id = ?")->execute([$newName, $docId]);
                }
                header("Location: tracker.php?report=misclassified&msg=" . urlencode("✅ File renamed."));
                $logger->log($_SESSION['user_id'], 'RENAME_DOCUMENT', "Renamed document ID: $docId from {$currentDoc['original_name']} to $newName");
                exit;
            }
        }
        header("Location: tracker.php");
        exit;
    }
}

// ---------- 3) SCANNERS & DATA MAPPING ----------
// 3. FETCH CONFIGURATION (Dynamic)
$REQUIRED_DOCS = [];
$reqList = []; // For the management modal

try {
    $stmt = $pdo->query("SELECT * FROM document_requirements ORDER BY id ASC");
    $reqList = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($reqList as $r) {
        $REQUIRED_DOCS[$r['name']] = array_map('trim', explode(',', $r['keywords']));
    }
} catch (Exception $e) {
    // [AUTO-FIX] Table missing? Create it and seed defaults immediately.
    $pdo->exec("CREATE TABLE IF NOT EXISTS document_requirements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        keywords TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT INTO document_requirements (name, keywords) VALUES ('201 Files', '201, PDS, Data Sheet, Resume'),('Valid ID', 'ID, Passport, License, SSS, PhilHealth'),('Contract', 'Contract, Appointment, Offer'),('Medical', 'Medical, Fit to Work, Exam'),('Clearance', 'NBI, Police, Barangay')");

    // Retry fetch
    $stmt = $pdo->query("SELECT * FROM document_requirements ORDER BY id ASC");
    $reqList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reqList as $r) {
        $REQUIRED_DOCS[$r['name']] = array_map('trim', explode(',', $r['keywords']));
    }
}
if (empty($REQUIRED_DOCS) && empty($reqList)) {
    // If DB is empty but exists, we might want to show nothing or defaults. 
    // Let's respect the empty DB (user deleted all).
}

// [NEW] MISCLASSIFIED SCAN LOGIC
$misclassifiedDocs = [];
if (isset($_GET['report']) && $_GET['report'] === 'misclassified') {
    // Fetch all active documents with employee info
    $sql = "SELECT d.id, d.file_uuid, d.original_name, d.category, d.employee_id as emp_id, e.first_name, e.last_name 
            FROM documents d 
            LEFT JOIN employees e ON d.employee_id = e.emp_id 
            WHERE d.deleted_at IS NULL";
    $allDocsScan = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allDocsScan as $doc) {
        $name = $doc['original_name'];
        $cat  = $doc['category'];
        $issue = null;
        $suggested = null;

        // 1. Check if current category keywords match filename
        if (isset($REQUIRED_DOCS[$cat]) && $cat !== 'Others') {
            $matchFound = false;
            foreach ($REQUIRED_DOCS[$cat] as $k) {
                if (stripos($name, $k) !== false) {
                    $matchFound = true;
                    break;
                }
            }
            if (!$matchFound) {
                $issue = "Keyword Mismatch";
            }
        }

        // 2. If Category is 'Others' OR unknown OR we found a mismatch, check if it belongs elsewhere
        if ($cat === 'Others' || !isset($REQUIRED_DOCS[$cat]) || $issue) {
            foreach ($REQUIRED_DOCS as $reqCat => $keywords) {
                if ($reqCat === 'Others') continue;
                foreach ($keywords as $k) {
                    if (stripos($name, $k) !== false) {
                        $issue = "Potential: $reqCat";
                        $suggested = $reqCat;
                        break 2;
                    }
                }
            }
            // If it's Others and we didn't find a suggestion, we don't flag it
            if ($cat === 'Others' && !$suggested) {
                $issue = null;
            }
        }

        if ($issue) {
            $doc['issue'] = $issue;
            if ($suggested) $doc['suggested'] = $suggested;
            $misclassifiedDocs[] = $doc;
        }
    }
}

// 4. GET FILTERS
$dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$compliance = isset($_GET['compliance']) ? trim($_GET['compliance']) : '';
$search = Validator::sanitizeSearch($search);

// 4. FETCH EMPLOYEES
// [FIX] Check if last_reminded column exists to prevent crash
$hasLastReminded = false;
try {
    $chk = $pdo->query("SHOW COLUMNS FROM employees LIKE 'last_reminded'");
    if ($chk->rowCount() > 0) $hasLastReminded = true;
} catch (Exception $e) {
}

$selectCols = "emp_id, first_name, last_name, dept, job_title, status, email";
if ($hasLastReminded) $selectCols .= ", last_reminded";

$sql = "SELECT $selectCols FROM employees WHERE 1=1";
$params = [];

if (!empty($dept)) {
    $sql .= " AND dept LIKE ?";
    $params[] = "%{$dept}%";
}
if (!empty($type)) {
    $sql .= " AND (employment_type = ? OR agency_name = ?)";
    $params[] = $type;
    $params[] = $type;
}
if (!empty($compliance)) {
    // We filter compliance in PHP later, but we keep this param for form persistence
}
if (!empty($search)) {
    // [FIX] Multi-term search (Any order)
    $terms = preg_split('/[\s,]+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($terms as $term) {
        $sql .= " AND (emp_id LIKE ? OR last_name LIKE ? OR first_name LIKE ?)";
        $t = "%$term%";
        $params[] = $t;
        $params[] = $t;
        $params[] = $t;
    }
}
$sql .= " ORDER BY last_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// [NEW] Fuzzy Search Logic
$didYouMean = null;
$didYouMeanLink = "#";
if (empty($employees) && !empty($search)) {
    $closest = SearchHelper::findBestMatch($pdo, $search);
    if ($closest) {
        $didYouMean = $closest;
        $didYouMeanLink = "tracker.php?search=" . urlencode($closest);
    }
}

// 5. FETCH ALL DOCUMENTS (Optimized: 1 Query)
// We fetch all docs and map them to employees in PHP to avoid 1000+ SQL queries.
// [FIX] Only fetch active documents (exclude soft-deleted ones)
$hasDeletedAt = false;
try {
    $chk = $pdo->query("SHOW COLUMNS FROM documents LIKE 'deleted_at'");
    if ($chk->rowCount() > 0) $hasDeletedAt = true;
} catch (Exception $e) {
}

$docSql = "SELECT employee_id, category, original_name FROM documents WHERE 1=1";
if ($hasDeletedAt) $docSql .= " AND deleted_at IS NULL";

$docStmt = $pdo->query($docSql);
$allDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

// Map Docs:  $docsMap['1001'] = ['Medical' => true, 'Contract' => true]
$docsMap = [];
foreach ($allDocs as $d) {
    $empId = $d['employee_id'];
    $cat   = $d['category']; // e.g. "Medical"
    $name  = $d['original_name'];

    $docMatched = false;

    // Check against our Required List (Fuzzy Match)
    foreach ($REQUIRED_DOCS as $reqKey => $keywords) {
        // If Category matches OR Filename matches keyword
        if (stripos($cat, $reqKey) !== false) {
            $docsMap[$empId][$reqKey] = true;
            $docMatched = true;
        } else {
            foreach ($keywords as $k) {
                if (stripos($name, $k) !== false || stripos($cat, $k) !== false) {
                    $docsMap[$empId][$reqKey] = true;
                    $docMatched = true;
                    break;
                }
            }
        }
    }
    // [NEW] If no specific requirement matched, mark as 'Others' if that requirement exists
    if (!$docMatched && isset($REQUIRED_DOCS['Others'])) {
        $docsMap[$empId]['Others'] = true;
    }
}

// [NEW] FETCH EXEMPTIONS
$exemptMap = [];
try {
    $exStmt = $pdo->query("SELECT employee_id, requirement_name FROM document_exemptions");
    while ($row = $exStmt->fetch(PDO::FETCH_ASSOC)) {
        $exemptMap[$row['employee_id']][$row['requirement_name']] = true;
    }
} catch (Exception $e) {
}

// 6. FILTER BY COMPLIANCE (PHP Side)
if ($compliance !== '') {
    $filtered = [];
    foreach ($employees as $emp) {
        $id = $emp['emp_id'];
        $have = 0;
        $totalReq = count($REQUIRED_DOCS);
        foreach ($REQUIRED_DOCS as $reqKey => $keywords) {
            if (isset($docsMap[$id][$reqKey]) || isset($exemptMap[$id][$reqKey])) $have++;
        }
        $percent = ($totalReq > 0) ? ($have / $totalReq) * 100 : 0;

        if ($compliance === 'complete' && $percent >= 100) $filtered[] = $emp;
        elseif ($compliance === 'incomplete' && $percent < 100) $filtered[] = $emp;
        elseif ($compliance === 'in_progress' && $percent > 0 && $percent < 100) $filtered[] = $emp;
        elseif ($compliance === 'empty' && $percent == 0) $filtered[] = $emp;
    }
    $employees = $filtered;
}

// [OPTIMIZATION] Paginate the results to prevent browser freezing
$totalRows = count($employees);
$perPage = 50;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = max(1, $totalPages);
$offset = ($page - 1) * $perPage;
$paginatedEmployees = array_slice($employees, $offset, $perPage);

?>
<?php include 'header.php'; ?>
<style>
    body {
        background-color: var(--bs-body-bg);
        color: var(--bs-body-color);
        font-size: 0.9rem;
    }

    .progress {
        height: 20px;
        border-radius: 10px;
        background-color: var(--bs-secondary-bg);
    }

    .icon-check {
        color: #198754;
        font-size: 1.2rem;
    }

    /* Green Check */
    .icon-cross {
        color: #dc3545;
        font-size: 1.2rem;
        opacity: 0.6;
    }

    /* Red X */
    .card-header {
        background: #2c3e50;
        color: white;
    }

    .table-hover tbody tr:hover {
        background-color: #f1f1f1;
    }

    .cursor-pointer {
        cursor: pointer;
    }

    .icon-cross:hover {
        opacity: 1;
    }

    /* [NEW] Tag Input Styles */
    .tag-container {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        padding: 5px;
        border: 1px solid var(--bs-border-color);
        border-radius: 0.25rem;
        background-color: var(--bs-body-bg);
        min-height: 38px;
        align-items: center;
    }

    .tag-container:focus-within {
        border-color: #86b7fe;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }

    .tag-chip {
        background-color: var(--bs-tertiary-bg);
        color: var(--bs-body-color);
        border: 1px solid var(--bs-border-color);
        border-radius: 3px;
        padding: 2px 6px;
        font-size: 0.85rem;
        display: flex;
        align-items: center;
    }

    .tag-chip span {
        margin-right: 5px;
    }

    .tag-chip i {
        cursor: pointer;
        font-size: 0.8rem;
        color: #6c757d;
    }

    .tag-chip i:hover {
        color: #dc3545;
    }

    .tag-input {
        border: none;
        outline: none;
        flex-grow: 1;
        min-width: 100px;
        font-size: 0.9rem;
        padding: 2px;
    }
</style>

<div class="container-fluid px-4">

    <div class="card shadow-sm mb-4">
        <!-- FILTERS -->
        <div class="card-body py-3">
            <form class="row g-3 align-items-center">
                <div class="col-12 col-md-auto">
                    <label class="fw-bold">Filter Dept:</label>
                </div>
                <div class="col-12 col-md-auto">
                    <select name="dept" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php
                        $depts = ['SQP', 'SIGCOM', 'PSS', 'OCS', 'ADMIN', 'HMS', 'RAS', 'TRS', 'LMS', 'DOS', 'CTS', 'BFS', 'WHS', 'GUNJIN'];
                        foreach ($depts as $d) echo "<option value='$d' " . ($dept == $d ? 'selected' : '') . ">$d</option>";
                        ?>
                        <option disabled>──────────</option>
                        <?php
                        // Add dynamic departments found in DB if not in list
                        ?>
                    </select>
                </div>
                <div class="col-12 col-md-auto">
                    <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Agencies</option>
                        <?php
                        // [FIX] Dynamically fetch distinct employment types and agency names from the database
                        $agencies = [];
                        try {
                            $typeStmt = $pdo->query("SELECT DISTINCT employment_type AS val FROM employees WHERE employment_type IS NOT NULL AND employment_type != '' 
                                                     UNION 
                                                     SELECT DISTINCT agency_name AS val FROM employees WHERE agency_name IS NOT NULL AND agency_name != ''");
                            while ($row = $typeStmt->fetch(PDO::FETCH_ASSOC)) {
                                $agencies[] = $row['val'];
                            }
                            sort($agencies); // Sort alphabetically
                        } catch (Exception $e) {
                            // Fallback list just in case the query fails
                            $agencies = ['Regular', 'Probationary', 'Contractual', 'Project-Based'];
                        }

                        foreach ($agencies as $val) {
                            $sel = ($type === $val) ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($val) . "' $sel>" . htmlspecialchars($val) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="col-12 col-md-auto">
                    <select name="compliance" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <option value="complete" <?php echo ($compliance == 'complete' ? 'selected' : ''); ?>>✅ Complete (100%)</option>
                        <option value="incomplete" <?php echo ($compliance == 'incomplete' ? 'selected' : ''); ?>>⚠️ Incomplete</option>
                        <option value="in_progress" <?php echo ($compliance == 'in_progress' ? 'selected' : ''); ?>>🔄 In Progress (1-99%)</option>
                        <option value="empty" <?php echo ($compliance == 'empty' ? 'selected' : ''); ?>>❌ No Documents (0%)</option>
                    </select>
                </div>
                <div class="col-12 col-md-auto ms-auto">
                    <div class="input-group input-group-sm">
                        <input type="text" name="search" id="trackerSearch" class="form-control" placeholder="Search Name..." value="<?php echo htmlspecialchars($search); ?>" maxlength="50" pattern="[a-zA-Z0-9\-_ ,]+" title="Allowed: Letters, Numbers, Spaces, Dashes, Underscores, Comma" list="search_suggestions" oninput="this.value = this.value.replace(/[^a-zA-Z0-9\-_ ,]/g, '')">
                        <?php if ($search): ?>
                            <a href="tracker.php" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                        <?php endif; ?>
                        <datalist id="search_suggestions">
                            <?php foreach ($employees as $empSugg): ?>
                                <option value="<?php echo htmlspecialchars($empSugg['last_name'] . ', ' . $empSugg['first_name'] . ' (' . $empSugg['emp_id'] . ')'); ?>">
                                <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                <div class="col-6 col-md-auto">
                    <button type="submit" class="btn btn-primary btn-sm">Search</button>
                </div>
                <div class="col-6 col-md-auto">
                    <button type="submit" formaction="print_tracker.php" formtarget="_blank" class="btn btn-dark btn-sm"><i class="bi bi-printer"></i> Print List</button>
                </div>
                <div class="col-12 col-md-auto">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('selectAll').click();"><i class="bi bi-check-all"></i> Select All</button>
                </div>
                <div class="col-12 col-md-auto">
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="submitBulkReminders()"><i class="bi bi-clipboard-check"></i> Log Bulk Reminders</button>
                </div>
                <div class="col-12 col-md-auto">
                    <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#misclassifiedModal" onclick="runIntegrityScan()"><i class="bi bi-shield-check"></i> Integrity Fix Tool</button>
                </div>
                <?php if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])): ?>
                    <div class="col-12 col-md-auto">
                        <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#quickFixOthersModal"><i class="bi bi-magic"></i> Quick Fix 'Others'</button>
                    </div>
                <?php endif; ?>
                <?php if (in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])): ?>
                    <div class="col-12 col-md-auto ms-auto">
                        <a href="tracker.php" class="btn btn-outline-secondary btn-sm">Reset Filters</a>
                    </div>
                    <div class="col-12 col-md-auto ms-2 border-start ps-3">
                        <button type="button" class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#manageReqModal"><i class="bi bi-gear-fill"></i> Manage Requirements</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($didYouMean): ?>
        <div class="alert alert-info text-center shadow-sm mb-3">
            <i class="bi bi-lightbulb-fill me-2"></i> Did you mean:
            <a href="<?php echo $didYouMeanLink; ?>" class="fw-bold text-dark text-decoration-underline"><?php echo htmlspecialchars($didYouMean); ?></a>?
        </div>
    <?php endif; ?>

    <div class="card shadow">
        <div class="card-header d-flex justify-content-between">

            <h6 class="mb-0 pt-1">Compliance Matrix</h6>
            <?php // ---------- 5) COMPLIANCE MATRIX ---------- 
            ?>
            <span class="badge bg-light text-dark"><?php echo $totalRows; ?> Employees Found</span>
        </div>
        <form id="bulkForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="bulk_reminders">
            <div class="card-body p-0 table-responsive">
                <table class="table table-bordered table-hover mb-0 text-center align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width: 40px;"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                            <th class="text-start ps-3">Employee <span id="selection-count" class="badge bg-primary ms-1" style="display:none">0</span></th>
                            <th width="15%">Progress</th>
                            <?php foreach ($REQUIRED_DOCS as $catName => $k): ?>
                                <th><?php echo htmlspecialchars($catName); ?></th>
                            <?php endforeach; ?>
                            <th width="10%">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paginatedEmployees as $emp):
                            $id = $emp['emp_id'];

                            // Calculate Score
                            $totalReq = count($REQUIRED_DOCS);
                            $have = 0;
                            $rowCells = [];
                            $missingItems = []; // Track what is missing for the modal

                            // Check each requirement
                            foreach ($REQUIRED_DOCS as $reqKey => $keywords) {
                                $isPresent = isset($docsMap[$id][$reqKey]);
                                $isExempt  = isset($exemptMap[$id][$reqKey]);

                                if ($isPresent || $isExempt) $have++;

                                // Determine Cell Status
                                if ($isPresent) $rowCells[$reqKey] = 'ok';
                                elseif ($isExempt) $rowCells[$reqKey] = 'na';
                                else {
                                    $rowCells[$reqKey] = 'missing';
                                    $missingItems[] = $reqKey;
                                }
                            }

                            // [NEW] Check if 'Others' category has files (if 'Others' is a requirement)
                            if (isset($REQUIRED_DOCS['Others']) && isset($docsMap[$id]['Others'])) {
                                $rowCells['Others'] = 'ok';
                            }

                            $percent = ($totalReq > 0) ? ($have / $totalReq) * 100 : 0;

                            // Color logic
                            $barColor = 'bg-danger';
                            if ($percent > 40) $barColor = 'bg-warning';
                            if ($percent > 80) $barColor = 'bg-info';
                            if ($percent == 100) $barColor = 'bg-success';

                            // Encode missing items for JS
                            $missingJson = htmlspecialchars(json_encode($missingItems), ENT_QUOTES, 'UTF-8');
                        ?>
                            <tr>
                                <td><input type="checkbox" name="emp_ids[]" value="<?php echo htmlspecialchars($id); ?>" class="form-check-input emp-checkbox"></td>
                                <td class="text-start ps-3">
                                    <div class="fw-bold"><?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($emp['dept']); ?> | <?php echo htmlspecialchars($emp['job_title']); ?></div>
                                </td>
                                <td>
                                    <div class="progress">
                                        <div class="progress-bar <?php echo $barColor; ?>" style="width: <?php echo $percent; ?>%">
                                            <?php echo round($percent); ?>%
                                        </div>
                                    </div>
                                </td>
                                <?php foreach ($rowCells as $reqName => $status): ?>
                                    <td class="align-middle">
                                        <?php if ($status === 'ok'): ?>
                                            <i class="bi bi-check-circle-fill icon-check" title="Submitted"></i>
                                        <?php elseif ($status === 'na'): ?>
                                            <span class="badge bg-secondary cursor-pointer" onclick="toggleExempt(<?php echo htmlspecialchars(json_encode($id), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($reqName), ENT_QUOTES, 'UTF-8'); ?>)" title="Click to mark as Required">N/A</span>
                                        <?php else: ?>
                                            <div class="d-flex justify-content-center align-items-center gap-1">
                                                <button type="button" class="btn btn-link p-0 border-0" onclick="toggleExempt(<?php echo htmlspecialchars(json_encode($id), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($reqName), ENT_QUOTES, 'UTF-8'); ?>)" title="Missing. Click to mark as Can't Comply (N/A)">
                                                    <i class="bi bi-x-circle-fill icon-cross cursor-pointer"></i>
                                                </button>
                                                <!-- [NEW] Quick Upload Button -->
                                                <a href="upload_form.php?emp_id=<?php echo htmlspecialchars($id); ?>&category=<?php echo urlencode($reqName); ?>" class="btn btn-sm btn-light py-0 px-1 border" title="Upload <?php echo htmlspecialchars($reqName); ?>"><i class="bi bi-upload text-primary" style="font-size: 0.7rem;"></i></a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td>
                                    <?php if ($percent == 100): ?>
                                        <span class="badge bg-success">COMPLETE</span>
                                    <?php elseif ($percent == 0): ?>
                                        <span class="badge bg-danger">EMPTY</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">INCOMPLETE</span>
                                    <?php endif; ?>

                                    <?php if ($percent < 100 && in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])): ?>
                                        <?php if (empty($emp['email'])): ?>
                                            <span class="badge bg-light text-muted border ms-1" title="No Email Address">No Email</span>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-1 ms-1"
                                                onclick="openReminderModal(<?php echo htmlspecialchars(json_encode($emp['emp_id']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($emp['first_name']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars($missingJson, ENT_QUOTES, 'UTF-8'); ?>)"
                                                title="Send Reminder">
                                                <i class="bi bi-envelope"></i>
                                            </button>
                                            <?php if ($hasLastReminded && !empty($emp['last_reminded'])): ?>
                                                <div style="font-size: 0.65rem;" class="text-muted mt-1">
                                                    Sent: <?php echo date('M d', strtotime($emp['last_reminded'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>

    <!-- [NEW] Pagination Controls -->
    <?php if ($totalPages > 1): ?>
        <nav class="mt-4" aria-label="Tracker pagination">
            <ul class="pagination justify-content-center">
                <?php
                $qs = $_GET; // Preserve current filters

                // Previous Button
                $qs['page'] = max(1, $page - 1);
                $prevUrl = '?' . http_build_query($qs);
                echo '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="' . $prevUrl . '">&laquo; Prev</a></li>';

                // Page Numbers (Windowed)
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++) {
                    $qs['page'] = $i;
                    $url = '?' . http_build_query($qs);
                    $active = ($page == $i) ? 'active' : '';
                    echo '<li class="page-item ' . $active . '"><a class="page-link" href="' . $url . '">' . $i . '</a></li>';
                }

                // Next Button
                $qs['page'] = min($totalPages, $page + 1);
                $nextUrl = '?' . http_build_query($qs);
                echo '<li class="page-item ' . ($page >= $totalPages ? 'disabled' : '') . '"><a class="page-link" href="' . $nextUrl . '">Next &raquo;</a></li>';
                ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php // ---------- 6) MODAL DIALOGS ---------- 
?>
<!-- MANAGE REQUIREMENTS MODAL -->
<div class="modal fade" id="manageReqModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-gear-fill"></i> Manage Requirements</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small">
                    <strong>How it works:</strong> Add a category name and tags (keywords). This will also appear as a category in the <strong>Upload Form</strong>. If an employee has a document matching ANY of the tags (in category or filename), it counts as "Submitted".
                </div>

                <!-- LIST -->
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Requirement Name</th>
                            <th>Tags / Keywords (Comma Separated)</th>
                            <th width="100">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reqList as $r): ?>
                            <tr>
                                <td><input type="text" id="name_<?php echo $r['id']; ?>" class="form-control form-control-sm" value="<?php echo htmlspecialchars($r['name']); ?>" required maxlength="100" pattern="[a-zA-Z0-9\s\-\(\)\.]+" title="Alphanumeric, spaces, dots, parens, dashes"></td>
                                <td>
                                    <!-- [NEW] Visual Tag Editor for Edit Row -->
                                    <div class="tag-container" id="tags_<?php echo $r['id']; ?>" onclick="focusTagInput(this)">
                                        <!-- Tags injected by JS -->
                                        <input type="text" class="tag-input" placeholder="Add tag..." onkeydown="handleTagKey(event, this)">
                                    </div>
                                    <input type="hidden" id="keys_<?php echo $r['id']; ?>" value="<?php echo htmlspecialchars($r['keywords']); ?>">
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary" onclick="editReq(<?php echo $r['id']; ?>)" title="Save Changes"><i class="bi bi-save"></i></button>
                                    <button type="button" class="btn btn-sm btn-danger delete-req-btn" data-id="<?php echo $r['id']; ?>" title="Delete Requirement"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reqList)): ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted">No custom requirements found. Add one below.</td>
                            </tr>
                        <?php endif; ?>
                        <!-- ADD NEW ROW -->
                        <tr class="table-warning">
                            <td>
                                <input type="text" id="new_req_name" class="form-control form-control-sm" placeholder="New Requirement" list="req_suggestions" required maxlength="100" pattern="[a-zA-Z0-9\s\-\(\)\.]+" title="Alphanumeric, spaces, dots, parens, dashes">
                                <datalist id="req_suggestions">
                                    <option value="Tor / Diploma">
                                    <option value="Certificate of Employment">
                                    <option value="Marriage Contract">
                                    <option value="Birth Certificate">
                                    <option value="Health Card">
                                </datalist>
                            </td>
                            <td>
                                <!-- [NEW] Visual Tag Editor for Add Row -->
                                <div class="tag-container" id="new_tags_container" onclick="focusTagInput(this)">
                                    <input type="text" class="tag-input" id="new_tag_input" placeholder="Type & Enter..." onkeydown="handleTagKey(event, this)">
                                </div>
                                <input type="hidden" id="new_req_keywords">
                            </td>
                            <td><button type="button" class="btn btn-sm btn-success w-100" onclick="addReq()"><i class="bi bi-plus-lg"></i> Add</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<form id="deleteReqForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <input type="hidden" name="action" value="delete_req">
    <input type="hidden" name="req_id" id="deleteReqId">
</form>

<form id="editReqForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <input type="hidden" name="action" value="edit_req">
    <input type="hidden" name="req_id" id="editReqId">
    <input type="hidden" name="req_name" id="editReqName">
    <input type="hidden" name="req_keywords" id="editReqKeys">
</form>

<form id="addReqForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <input type="hidden" name="action" value="add_req">
    <input type="hidden" name="req_name" id="addReqName">
    <input type="hidden" name="req_keywords" id="addReqKeys">
</form>

<form id="exemptForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <input type="hidden" name="action" value="toggle_exempt">
    <input type="hidden" name="emp_id" id="exemptEmpId">
    <input type="hidden" name="req_name" id="exemptReqName">
    <input type="hidden" name="redirect_query" value="<?php echo h($_SERVER['QUERY_STRING']); ?>">
</form>

<!-- REMINDER MODAL -->
<div class="modal fade" id="reminderModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-envelope-paper"></i> Send Reminder</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="send_reminder">
                <input type="hidden" name="emp_id" id="remindEmpId">
                <p>Select the missing documents to remind <strong><span id="remindEmpName"></span></strong> about:</p>
                <div id="missingListContainer" class="list-group"></div>

                <div class="mt-4">
                    <label class="fw-bold text-primary small">Message Template to Copy:</label>
                    <div class="input-group">
                        <textarea id="copyMessageText" class="form-control" rows="5" readonly></textarea>
                        <button type="button" class="btn btn-outline-primary" onclick="copyReminderText()"><i class="bi bi-clipboard"></i> Copy</button>
                    </div>
                    <div class="form-text text-warning small mt-1"><i class="bi bi-info-circle-fill"></i> Email system disabled (MHI Policy). Copy this text and send it manually via Teams, Viber, or SMS.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">Mark as Reminded</button>
            </div>
        </form>
    </div>
</div>

<!-- MISCLASSIFIED REPORT MODAL -->
<div class="modal fade" id="misclassifiedModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill"></i> Potential Misclassified Files</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light small border">
                    This report lists files where:
                    <ul class="mb-0 ps-3">
                        <li>The <strong>Filename</strong> does not contain any of the <strong>Keywords</strong> for its assigned Category.</li>
                        <li>Files in <strong>Others/Custom</strong> categories that match a known requirement keyword.</li>
                    </ul>
                </div>
                <div class="d-grid mb-3">
                    <a href="tracker.php?report=misclassified" class="btn btn-primary btn-sm">Run Scan Now</a>
                </div>

                <?php if (isset($_GET['report']) && $_GET['report'] === 'misclassified'): ?>
                    <?php if (empty($misclassifiedDocs)): ?>
                        <div class="alert alert-success text-center">✅ No misclassified files found!</div>
                    <?php else: ?>
                        <form method="POST" id="bulkMoveForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action" value="bulk_move">

                            <div class="row g-2 align-items-end mb-3 p-2 border rounded bg-light">
                                <div class="col-md-5">
                                    <label class="form-label small fw-bold mb-1">1. New Category (Required)</label>
                                    <select name="new_category" class="form-select form-select-sm" required>
                                        <option value="">-- Select Category --</option>
                                        <?php foreach ($REQUIRED_DOCS as $cat => $k): ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                        <?php endforeach; ?>
                                        <option value="Others">Others</option>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label small fw-bold mb-1">2. Transfer Owner (Optional)</label>
                                    <div class="position-relative">
                                        <input type="hidden" name="target_emp_id" id="bulkMoveTargetId">
                                        <input type="text" id="bulkMoveSearch" class="form-control form-control-sm"
                                            placeholder="Search Employee..." autocomplete="off"
                                            maxlength="50" pattern="[a-zA-Z0-9\-_ \.\']+"
                                            oninput="this.value = this.value.replace(/[^a-zA-Z0-9\-_ \.\']/g, '')">
                                        <div id="bulkMoveSuggestions" class="list-group position-absolute w-100 shadow" style="z-index: 1050; display: none; max-height: 200px; overflow-y: auto;"></div>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-sm btn-primary w-100" onclick="confirmBulkMove()">Move Selected</button>
                                </div>
                            </div>

                            <div class="mb-2 d-flex justify-content-end">
                                <button type="button" class="btn btn-xs btn-outline-secondary" onclick="selectAllDocs()" title="Select All Listed"><i class="bi bi-check-all"></i> Select All</button>
                            </div>

                            <table class="table table-sm table-hover small align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:30px;"><input type="checkbox" class="form-check-input" onclick="toggleBulk(this)"></th>
                                        <th>Employee</th>
                                        <th>File Name</th>
                                        <th>Current Category</th>
                                        <th>Issue</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($misclassifiedDocs as $doc): ?>
                                        <tr onclick="toggleRowCheck(event, this)" style="cursor: pointer;">
                                            <td><input type="checkbox" name="doc_ids[]" value="<?php echo $doc['id']; ?>" class="form-check-input bulk-check"></td>
                                            <td>
                                                <?php echo htmlspecialchars($doc['last_name'] . ', ' . $doc['first_name']); ?>
                                                <br><span class="text-muted" style="font-size:0.7em"><?php echo htmlspecialchars($doc['emp_id']); ?></span>
                                            </td>
                                            <td class="text-danger fw-bold"><?php echo htmlspecialchars($doc['original_name']); ?></td>
                                            <td><?php echo htmlspecialchars($doc['category']); ?></td>
                                            <td>
                                                <?php if (isset($doc['suggested'])): ?>
                                                    <span class="badge bg-warning text-dark">Matches: <?php echo htmlspecialchars($doc['suggested']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Keyword Mismatch</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="view_doc.php?id=<?php echo $doc['file_uuid']; ?>&embed=1" target="_blank" class="btn btn-sm btn-info text-white py-0 px-1" title="Preview"><i class="bi bi-eye"></i></a>
                                                <button type="button" class="btn btn-xs btn-outline-primary" onclick="renameFile(<?php echo (int)$doc['id']; ?>, <?php echo htmlspecialchars(json_encode($doc['original_name']), ENT_QUOTES, 'UTF-8'); ?>)">Rename</button>
                                                <button type="button" class="btn btn-xs btn-outline-dark" onclick="prepareMove(event, '<?php echo $doc['id']; ?>')">Select</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- RENAME FORM (Hidden) -->
<form id="renameForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <input type="hidden" name="action" value="rename_file">
    <input type="hidden" name="doc_id" id="renameDocId">
    <input type="hidden" name="new_name" id="renameNewName">
</form>

<script src="assets/bootstrap.bundle.min.js"></script>
<?php // ---------- 7) JAVASCRIPT & TAG LOGIC ---------- 
?>
<script src="assets/sweetalert2.all.min.js"></script>
<script>
    // [NEW] Tag Logic
    function initTags(containerId, hiddenInputId) {
        const container = document.getElementById(containerId);
        const hiddenInput = document.getElementById(hiddenInputId);
        if (!container || !hiddenInput) return;

        const input = container.querySelector('.tag-input');
        const initialVal = hiddenInput.value;

        // Clear existing chips (keep input)
        Array.from(container.querySelectorAll('.tag-chip')).forEach(el => el.remove());

        if (initialVal) {
            initialVal.split(',').map(s => s.trim()).filter(s => s).forEach(tag => {
                addChip(container, tag);
            });
        }
    }

    function addChip(container, text) {
        const input = container.querySelector('.tag-input');
        const chip = document.createElement('div');
        chip.className = 'tag-chip';

        const label = document.createElement('span');
        label.textContent = text;

        const closeIcon = document.createElement('i');
        closeIcon.className = 'bi bi-x';
        closeIcon.setAttribute('role', 'button');
        closeIcon.setAttribute('aria-label', 'Remove tag');
        closeIcon.addEventListener('click', () => removeChip(closeIcon));

        chip.appendChild(label);
        chip.appendChild(closeIcon);
        container.insertBefore(chip, input);
    }

    function removeChip(icon) {
        const chip = icon.parentElement;
        const container = chip.parentElement;
        chip.remove();
        updateHiddenInput(container);
    }

    function handleTagKey(e, input) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            const val = input.value.trim().replace(/,/g, '');
            if (val) {
                addChip(input.parentElement, val);
                input.value = '';
                updateHiddenInput(input.parentElement);
            }
        } else if (e.key === 'Backspace' && !input.value) {
            const chips = input.parentElement.querySelectorAll('.tag-chip');
            if (chips.length > 0) {
                chips[chips.length - 1].remove();
                updateHiddenInput(input.parentElement);
            }
        }
    }

    function focusTagInput(container) {
        if (event.target === container) {
            container.querySelector('.tag-input').focus();
        }
    }

    function updateHiddenInput(container) {
        const chips = container.querySelectorAll('.tag-chip span');
        const values = Array.from(chips).map(c => c.innerText);
        // Find the hidden input associated with this container
        // For edit rows: container id is tags_123, hidden is keys_123
        // For add row: container is new_tags_container, hidden is new_req_keywords
        let hiddenId;
        if (container.id === 'new_tags_container') hiddenId = 'new_req_keywords';
        else hiddenId = container.id.replace('tags_', 'keys_');

        const hidden = document.getElementById(hiddenId);
        if (hidden) hidden.value = values.join(', ');
    }

    // Initialize all tags on load
    document.addEventListener('DOMContentLoaded', () => {
        <?php foreach ($reqList as $r): ?>
            initTags('tags_<?php echo $r['id']; ?>', 'keys_<?php echo $r['id']; ?>');
        <?php endforeach; ?>
        // Init new row
        initTags('new_tags_container', 'new_req_keywords');
    });

    // [NEW] Quick Fix Modal Initialization
    document.addEventListener('DOMContentLoaded', function() {
        const quickFixModal = document.getElementById('quickFixOthersModal');
        if (quickFixModal) {
            quickFixModal.addEventListener('show.bs.modal', function() {
                const contentDiv = document.getElementById('quickFixContent');
                const applyBtn = document.getElementById('applyQuickFixBtn');
                contentDiv.innerHTML = '<div class="text-center p-5 text-muted"><div class="spinner-border text-info mb-3"></div><p>Scanning documents...</p></div>';
                applyBtn.disabled = true;

                fetch('api/quick_fix_others.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success') {
                            if (data.suggestions.length === 0) {
                                contentDiv.innerHTML = '<div class="alert alert-success text-center">No "Others" documents found that can be re-categorized.</div>';
                            } else {
                                let tableHtml = `
                                    <table class="table table-sm table-hover align-middle small">
                                        <thead>
                                            <tr>
                                                <th style="width: 30px;"><input type="checkbox" class="form-check-input" id="selectAllQuickFix"></th>
                                                <th>Document</th>
                                                <th>Employee</th>
                                                <th>New Category</th>
                                                <th>Matched Keywords</th>
                                            </tr>
                                        </thead>
                                        <tbody>`;
                                // HTML escape helper
                                function escapeHtml(str) {
                                    if (!str) return '';
                                    const div = document.createElement('div');
                                    div.textContent = str;
                                    return div.innerHTML;
                                }

                                data.suggestions.forEach(s => {
                                    let optionsHtml = `<option value="">-- Select Category --</option>`;
                                    data.categories.forEach(cat => {
                                        const selected = (s.suggested_category === cat) ? 'selected' : '';
                                        optionsHtml += `<option value="${cat}" ${selected}>${cat}</option>`;
                                    });
                                    optionsHtml += `<option value="Others" ${s.suggested_category === null ? 'selected' : ''}>Others</option>`;

                                    tableHtml += `
                                        <tr>
                                            <td><input type="checkbox" class="form-check-input quick-fix-checkbox" onchange="toggleRowInput(this)"></td>
                                            <td>${escapeHtml(s.original_name)}</td>
                                            <td>${escapeHtml(s.employee_name)}</td>
                                            <td>
                                                <select name="fix_docs[${s.doc_id}]" class="form-select form-select-sm category-select" disabled>
                                                    ${optionsHtml}
                                                </select>
                                            </td>
                                            <td><small class="text-muted">${escapeHtml(s.matched_keywords || 'None')}</small></td>
                                        </tr>`;
                                });
                                tableHtml += `</tbody></table>`;
                                contentDiv.innerHTML = tableHtml;
                                applyBtn.disabled = false;

                                document.getElementById('selectAllQuickFix').addEventListener('change', function() {
                                    document.querySelectorAll('.quick-fix-checkbox').forEach(cb => {
                                        cb.checked = this.checked;
                                        toggleRowInput(cb);
                                    });
                                });
                            }
                        }
                    });
            });
        }
    });

    function toggleRowInput(checkbox) {
        const row = checkbox.closest('tr');
        const select = row.querySelector('.category-select');
        if (select) select.disabled = !checkbox.checked;
    }

    // [FIX] Delegated Event Listener for Delete Buttons in Modal
    document.addEventListener('click', function(e) {
        if (e.target.closest('.delete-req-btn')) {
            const btn = e.target.closest('.delete-req-btn');
            const reqId = btn.getAttribute('data-id');

            Swal.fire({
                title: 'Are you sure?',
                text: "This will permanently delete this requirement!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('deleteReqId').value = reqId;
                    document.getElementById('deleteReqForm').submit();
                }
            });
        }
    });

    function editReq(id) {
        const name = document.getElementById('name_' + id).value;
        // Value is already updated in hidden input by JS
        const keys = document.getElementById('keys_' + id).value;
        document.getElementById('editReqId').value = id;
        document.getElementById('editReqName').value = name;
        document.getElementById('editReqKeys').value = keys;
        document.getElementById('editReqForm').submit();
    }

    function addReq() {
        const name = document.getElementById('new_req_name').value.trim();
        // Value is already updated in hidden input by JS
        const keys = document.getElementById('new_req_keywords').value.trim();
        if (name && keys) {
            document.getElementById('addReqName').value = name;
            document.getElementById('addReqKeys').value = keys;
            document.getElementById('addReqForm').submit();
        } else {
            Swal.fire('Error', 'Please fill in both Name and Tags/Keywords.', 'warning');
        }
    }

    function toggleExempt(empId, reqName) {
        // Simple toggle without confirmation for speed, or add confirm if preferred
        document.getElementById('exemptEmpId').value = empId;
        document.getElementById('exemptReqName').value = reqName;
        document.getElementById('exemptForm').submit();
    }

    function openReminderModal(empId, name, missingItems) {
        document.getElementById('remindEmpId').value = empId;
        document.getElementById('remindEmpName').innerText = name;

        const container = document.getElementById('missingListContainer');
        container.innerHTML = '';

        if (missingItems.length === 0) {
            container.innerHTML = '<div class="alert alert-success">No missing documents!</div>';
        } else {
            missingItems.forEach(item => {
                const label = document.createElement('label');
                label.className = 'list-group-item';
                label.innerHTML = `<input class="form-check-input me-2" type="checkbox" name="reqs[]" value="${item}" checked> ${item}`;
                container.appendChild(label);
            });
        }

        const copyText = `Hi ${name},\n\nThis is a gentle reminder from HR regarding your 201 File. Please submit the following missing documents as soon as possible:\n\n- ` + missingItems.join('\n- ') + `\n\nThank you!`;
        document.getElementById('copyMessageText').value = copyText;

        new bootstrap.Modal(document.getElementById('reminderModal')).show();
    }

    function copyReminderText() {
        const copyText = document.getElementById('copyMessageText');
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value);
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            title: 'Message copied to clipboard!',
            showConfirmButton: false,
            timer: 2000
        });
    }

    // [NEW] Show Error Alerts (e.g. Duplicates)
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('error')) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: urlParams.get('error')
        });
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // [NEW] Handle URL Messages for Reminders
    if (urlParams.has('msg')) {
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: urlParams.get('msg'),
            timer: 2000,
            showConfirmButton: false
        });
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // [NEW] Auto-submit when selecting from datalist
    document.getElementById('trackerSearch').addEventListener('input', function() {
        var val = this.value;
        var opts = document.getElementById('search_suggestions').options;
        for (var i = 0; i < opts.length; i++) {
            if (opts[i].value === val) {
                this.form.submit();
                break;
            }
        }
    });

    function updateCount() {
        const count = document.querySelectorAll('.emp-checkbox:checked').length;
        const badge = document.getElementById('selection-count');
        if (badge) {
            badge.innerText = count;
            badge.style.display = count > 0 ? 'inline-block' : 'none';
        }
    }

    // [NEW] Select All Logic
    document.getElementById('selectAll').addEventListener('change', function(e) {
        document.querySelectorAll('.emp-checkbox').forEach(checkbox => {
            // [FIX] Only select visible rows
            if (checkbox.offsetParent !== null) {
                checkbox.checked = e.target.checked;
            }
        });
        updateCount();
    });
    document.querySelectorAll('.emp-checkbox').forEach(cb => {
        cb.addEventListener('change', updateCount);
    });

    // [NEW] Bulk Reminder with Progress Bar
    async function submitBulkReminders() {
        const checkboxes = document.querySelectorAll('.emp-checkbox:checked');
        if (checkboxes.length === 0) {
            Swal.fire('No Selection', 'Please select at least one employee.', 'warning');
            return;
        }

        const result = await Swal.fire({
            title: 'Log Bulk Reminders?',
            text: `This will mark ${checkboxes.length} employees as reminded today. (Actual messages must be sent manually via Teams/Viber).`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Log Reminders'
        });

        if (!result.isConfirmed) return;

        let sent = 0;
        let skipped = 0;
        let total = checkboxes.length;

        Swal.fire({
            title: 'Sending Emails...',
            html: `<div class="progress mb-2"><div id="email-progress" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div></div><span id="email-status">Initializing...</span>`,
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        for (let i = 0; i < total; i++) {
            const empId = checkboxes[i].value;
            const formData = new FormData();
            formData.append('action', 'ajax_send_reminder');
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');
            formData.append('emp_id', empId);

            try {
                const res = await fetch('tracker.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await res.json();
                if (data.status === 'success') sent++;
                else skipped++;
            } catch (e) {
                skipped++;
            }

            // Update Progress
            const pct = Math.round(((i + 1) / total) * 100);
            const bar = document.getElementById('email-progress');
            const stat = document.getElementById('email-status');
            if (bar) bar.style.width = `${pct}%`;
            if (stat) stat.innerText = `Processed ${i + 1} of ${total} (Sent: ${sent})`;
        }

        Swal.fire('Completed', `Sent: ${sent}, Skipped: ${skipped}`, 'success').then(() => {
            window.location.reload();
        });
    }

    // [NEW] Rename Logic
    function renameFile(id, oldName) {
        Swal.fire({
            title: 'Rename File',
            input: 'text',
            inputValue: oldName,
            showCancelButton: true,
            inputValidator: (value) => {
                if (!value) return 'You need to write something!'
            }
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('renameDocId').value = id;
                document.getElementById('renameNewName').value = result.value;
                document.getElementById('renameForm').submit();
            }
        });
    }

    function toggleBulk(source) {
        document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = source.checked);
    }

    // [NEW] Auto-open report modal if query param exists
    <?php if (isset($_GET['report']) && $_GET['report'] === 'misclassified'): ?>
        document.addEventListener('DOMContentLoaded', () => {
            new bootstrap.Modal(document.getElementById('misclassifiedModal')).show();
        });
    <?php endif; ?>

    // [NEW] Auto-open Quick Fix modal if query param exists (for Dashboard link)
    <?php if (isset($_GET['report']) && $_GET['report'] === 'quick_fix'): ?>
        document.addEventListener('DOMContentLoaded', () => {
            const qfModal = document.getElementById('quickFixOthersModal');
            if (qfModal) new bootstrap.Modal(qfModal).show();
        });
    <?php endif; ?>

    // [NEW] Bulk Move Search Logic
    const bulkSearch = document.getElementById('bulkMoveSearch');
    const bulkSuggestions = document.getElementById('bulkMoveSuggestions');
    const bulkTargetId = document.getElementById('bulkMoveTargetId');

    if (bulkSearch) {
        let debounce = null;
        bulkSearch.addEventListener('input', function() {
            const q = this.value.trim();
            bulkTargetId.value = ''; // Clear ID if typing

            if (q.length < 2) {
                bulkSuggestions.style.display = 'none';
                return;
            }

            clearTimeout(debounce);
            debounce = setTimeout(() => {
                fetch(`api/search_suggestions.php?q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(data => {
                        bulkSuggestions.innerHTML = '';
                        if (Array.isArray(data) && data.length > 0) {
                            bulkSuggestions.style.display = 'block';
                            data.slice(0, 5).forEach(emp => {
                                const item = document.createElement('a');
                                item.className = 'list-group-item list-group-item-action cursor-pointer small';

                                const nameStrong = document.createElement('strong');
                                nameStrong.textContent = `${emp.first_name} ${emp.last_name}`;

                                const idSpan = document.createElement('span');
                                idSpan.className = 'text-muted';
                                idSpan.textContent = ` (${emp.emp_id})`;

                                item.appendChild(nameStrong);
                                item.appendChild(idSpan);
                                item.onclick = () => {
                                    bulkSearch.value = `${emp.first_name} ${emp.last_name} - ${emp.emp_id}`;
                                    bulkTargetId.value = emp.emp_id;
                                    bulkSuggestions.style.display = 'none';
                                };
                                bulkSuggestions.appendChild(item);
                            });
                        } else {
                            bulkSuggestions.style.display = 'none';
                        }
                    });
            }, 250);
        });

        // Hide suggestions on click outside
        document.addEventListener('click', function(e) {
            if (!bulkSearch.contains(e.target) && !bulkSuggestions.contains(e.target)) {
                bulkSuggestions.style.display = 'none';
            }
        });
    }

    // [NEW] SweetAlert Confirmation for Bulk Move
    function confirmBulkMove() {
        const form = document.getElementById('bulkMoveForm');
        const count = form.querySelectorAll('input[name="doc_ids[]"]:checked').length;
        const cat = form.querySelector('select[name="new_category"]').value;
        const searchVal = document.getElementById('bulkMoveSearch').value;
        const targetId = document.getElementById('bulkMoveTargetId').value;

        if (count === 0) {
            Swal.fire('No Selection', 'Please select at least one document.', 'warning');
            return;
        }
        if (!cat) {
            Swal.fire('No Category', 'Please select a new category.', 'warning');
            return;
        }
        // [FIX] Validate Owner Search
        if (searchVal.trim() !== "" && targetId === "") {
            Swal.fire('Invalid Owner', 'You typed a name but didn\'t select from the list. Please click a name from the suggestions or clear the search box.', 'warning');
            return;
        }

        let msg = `Update ${count} document(s) to category "${cat}"?`;
        if (targetId) {
            msg += `<br><br><span class="text-danger fw-bold">Note: Ownership will be transferred to the selected employee.</span>`;
        }

        Swal.fire({
            title: 'Confirm Update?',
            html: msg,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            confirmButtonText: 'Yes, Apply Changes'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }

    // [NEW] Helper to select all in the report
    function selectAllDocs() {
        const checks = document.querySelectorAll('.bulk-check');
        const allChecked = Array.from(checks).every(c => c.checked);
        checks.forEach(c => c.checked = !allChecked);
    }

    // [NEW] Row click to toggle checkbox
    function toggleRowCheck(e, tr) {
        // Ignore clicks on buttons, links, inputs
        if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A' || e.target.tagName === 'I' || e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT') return;
        const cb = tr.querySelector('.bulk-check');
        if (cb) cb.checked = !cb.checked;
    }

    // [NEW] Prepare single move
    function prepareMove(e, id) {
        e.stopPropagation();
        // Uncheck all others
        document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = false);
        // Check this one
        // [FIX] Use robust selector for ID (handle string/int mismatch)
        const cb = document.querySelector(`input.bulk-check[value="${id}"]`);
        if (cb) cb.checked = true;

        // [FIX] Scroll to top form and highlight it
        const topForm = document.getElementById('bulkMoveForm');
        topForm.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });

        // Focus category
        const catSelect = document.querySelector('select[name="new_category"]');
        catSelect.focus();
        catSelect.classList.add('border-primary', 'shadow');
        setTimeout(() => catSelect.classList.remove('border-primary', 'shadow'), 2000);

        Swal.fire({
            toast: true,
            position: 'top',
            icon: 'info',
            title: '1. Document Selected',
            text: '2. Select Category above -> 3. Click "Move Selected"',
            timer: 3000,
            showConfirmButton: false
        });
    }
</script>

<script>
    // [UX STABILIZATION] Scroll Memory Helper
    // Prevents the page from jumping to the top after an action (e.g. toggle N/A, bulk reminders)
    const scrollKey = 'hr201_scroll_pos_' + window.location.pathname;

    window.addEventListener('beforeunload', () => {
        sessionStorage.setItem(scrollKey, window.scrollY);
    });

    const urlParamsForScroll = new URLSearchParams(window.location.search);
    if (urlParamsForScroll.has('msg') || urlParamsForScroll.has('error') || urlParamsForScroll.has('page')) {
        const savedPos = sessionStorage.getItem(scrollKey);
        if (savedPos) window.scrollTo(0, parseInt(savedPos));
    }
</script>

</body>

</html>