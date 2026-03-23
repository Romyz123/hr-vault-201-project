<?php
// ======================================================
// [FILE] public/system_recovery.php
// [PURPOSE] Advanced tools to recover lost data/files
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();
checkSessionTimeout($pdo); // [SECURITY] Enforce Timeout

// [UX] Fetch Client Timeout
$clientTimeout = 900;
try {
    $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
    $val = $stmt->fetchColumn();
    if ($val) $clientTimeout = (int)$val;
} catch (Exception $e) {
}

// 1. SECURITY: ADMIN ONLY
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

$config = require '../config/config.php';
$vaultPath = $config['VAULT_PATH'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vault' . DIRECTORY_SEPARATOR;
// [FIX] Ensure Vault Path is Absolute to prevent "stat failed" errors
$realVault = realpath($vaultPath);
if ($realVault) {
    $vaultPath = $realVault . DIRECTORY_SEPARATOR;
} else {
    // Ensure trailing separator even if realpath fails
    $vaultPath = rtrim($vaultPath, '/\\') . DIRECTORY_SEPARATOR;
}
$msg = "";
$security = new Security($pdo);
$logger = new Logger($pdo);

// [SECURITY] Generate CSRF token for form submissions
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 2. HANDLE ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // [SECURITY] CSRF Token Validation on all POST handlers
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("CSRF validation failed");
    }

    // --- RECOVER ORPHANED FILE ---
    if (isset($_POST['recover_file'])) {
        $filename = $_POST['filename'];

        // [FIX] Only allow recovering Vault files to DB for now
        if (strpos($filename, 'uploads/') === 0) {
            $msg = "❌ Cannot recover files from uploads folder to documents database. Please delete or move manually.";
            $realPath = false;
        } else {
            $filename = basename($filename);
            $realPath = $vaultPath . $filename;
        }

        if ($realPath && file_exists($realPath)) {
            // Create a new DB record for this file
            // We assign it to a placeholder ID so Admin can re-assign it later
            try {
                $stmt = $pdo->prepare("INSERT INTO documents (file_uuid, employee_id, original_name, file_path, category, uploaded_by, description, created_at) 
                                       VALUES (UUID(), 'RECOVERED', ?, ?, 'Recovered', ?, 'Recovered from Orphaned Files', NOW())");
                $stmt->execute([$filename, $filename, $_SESSION['user_id']]);

                $msg = "✅ File '$filename' recovered! Look for it under Employee ID: 'RECOVERED' in the database.";

                // Log it
                $logger = new Logger($pdo);
                $logger->log($_SESSION['user_id'], 'FILE_RECOVERY', "Recovered orphan file: $filename");
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ File not found on disk.";
        }
    }

    // --- DELETE ORPHANED FILE ---
    if (isset($_POST['delete_orphan'])) {
        $filename = $_POST['filename'];
        // [SECURITY] Neutralize double/triple URL encoding path traversal bypass
        $decoded = $filename;
        while (preg_match('/%[0-9a-fA-F]{2}/', $decoded)) {
            $decoded = urldecode($decoded);
        }
        if (strpos($decoded, '..') !== false || strpos($filename, '..') !== false || strpos($decoded, "\0") !== false) {
            die("Security Violation: Invalid path traversal detected.");
        }

        if (strpos($filename, 'uploads/') === 0) {
            // [FIX] Extract subdirectory and filename separately to prevent traversal
            $subPath = substr($filename, strlen('uploads/'));
            if (strpos($subPath, '/') !== false) {
                // Handle nested paths like uploads/avatars/file.png
                $parts = explode('/', $subPath, 2);
                $realPath = __DIR__ . '/uploads/' . basename($parts[0]) . '/' . basename($parts[1]);
            } else {
                $realPath = __DIR__ . '/uploads/' . basename($subPath);
            }
        } else {
            $realPath = $vaultPath . basename($filename);
        }

        if (file_exists($realPath)) {
            // [FIX] Try to force delete even if permissions are read-only
            if (!is_writable($realPath)) {
                @chmod($realPath, 0666);
            }
            if (@unlink($realPath)) {
                $msg = "🗑️ Orphaned file '$filename' permanently deleted.";
            } else {
                $err = error_get_last();
                $errMsg = $err ? ' Error: ' . htmlspecialchars($err['message']) : '';
                $msg = "❌ Could not delete file: " . htmlspecialchars(basename($filename)) . " (path: " . htmlspecialchars($realPath) . ")." . $errMsg;
            }
        } else {
            $msg = "❌ File not found at: " . htmlspecialchars($realPath);
        }
    }

    // --- DELETE ALL ORPHANS ---
    if (isset($_POST['delete_all_orphans'])) {
        $count = 0;
        $orphansToDelete = json_decode($_POST['orphan_list'], true);
        if (is_array($orphansToDelete)) {
            foreach ($orphansToDelete as $file) {
                // [SECURITY] Path traversal check
                $decoded = $file;
                while (preg_match('/%[0-9a-fA-F]{2}/', $decoded)) {
                    $decoded = urldecode($decoded);
                }
                if (strpos($decoded, '..') !== false || strpos($file, '..') !== false || strpos($decoded, "\0") !== false) continue;

                if (strpos($file, 'uploads/') === 0) {
                    // [FIX] Secure path construction
                    $subPath = substr($file, strlen('uploads/'));
                    if (strpos($subPath, '/') !== false) {
                        $parts = explode('/', $subPath, 2);
                        $realPath = __DIR__ . '/uploads/' . basename($parts[0]) . '/' . basename($parts[1]);
                    } else {
                        $realPath = __DIR__ . '/uploads/' . basename($subPath);
                    }
                } else {
                    $realPath = $vaultPath . basename($file);
                }

                if (file_exists($realPath)) {
                    if (!is_writable($realPath)) {
                        @chmod($realPath, 0666);
                    }
                    if (@unlink($realPath)) $count++;
                }
            }
        }
        $msg = "🗑️ Deleted $count orphaned files.";
    }

    // --- BULK DELETE ORPHANS ---
    if (isset($_POST['bulk_delete_orphans'])) {
        $filesToDelete = json_decode($_POST['orphan_list_json'], true);
        $count = 0;
        if (is_array($filesToDelete)) {
            foreach ($filesToDelete as $file) {
                // [SECURITY] Path traversal check
                $decoded = $file;
                while (preg_match('/%[0-9a-fA-F]{2}/', $decoded)) {
                    $decoded = urldecode($decoded);
                }
                if (strpos($decoded, '..') !== false || strpos($file, '..') !== false || strpos($decoded, "\0") !== false) continue;

                if (strpos($file, 'uploads/') === 0) {
                    // [FIX] Secure path construction
                    $subPath = substr($file, strlen('uploads/'));
                    if (strpos($subPath, '/') !== false) {
                        $parts = explode('/', $subPath, 2);
                        $realPath = __DIR__ . '/uploads/' . basename($parts[0]) . '/' . basename($parts[1]);
                    } else {
                        $realPath = __DIR__ . '/uploads/' . basename($subPath);
                    }
                } else {
                    $realPath = $vaultPath . basename($file);
                }

                if (file_exists($realPath)) {
                    if (!is_writable($realPath)) {
                        @chmod($realPath, 0666);
                    }
                    if (@unlink($realPath)) $count++;
                }
            }
        }
        $msg = "🗑️ Bulk Deleted $count orphaned files.";
    }

    // --- DELETE DUPLICATE RECORD ---
    if (isset($_POST['delete_duplicate'])) {
        // [FIX] Cast to int for security
        $id = (int)$_POST['doc_id'];

        if ($id <= 0) {
            $msg = "❌ Invalid document ID.";
        } else {
            // Fetch record and file path for possible restore
            $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
            $stmt->execute([$id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$doc) {
                $msg = "❌ Document not found.";
            } else {
                $path = $doc['file_path'];
                $fullPath = $vaultPath . $path;
                if (!file_exists($fullPath)) {
                    $fullPath = __DIR__ . '/uploads/' . $path;
                }

                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$id]);
                    $pdo->commit();

                    $msg = "🗑️ Duplicate record deleted.";
                    $logger = new Logger($pdo);
                    $logger->log($_SESSION['user_id'], 'DELETE_DUPLICATE', "Deleted duplicate document ID $id");

                    if (file_exists($fullPath) && !@unlink($fullPath)) {
                        // Rollback to keep DB in sync if file deletion fails
                        $pdo->beginTransaction();
                        $cols = array_keys($doc);
                        $colsStr = implode("`, `", $cols);
                        $valsStr = implode(", ", array_fill(0, count($cols), "?"));
                        $restoreStmt = $pdo->prepare("INSERT INTO documents (`$colsStr`) VALUES ($valsStr)");
                        $restoreStmt->execute(array_values($doc));
                        $pdo->commit();

                        $msg = "❌ Failed to delete file after removing database record; record restored.";
                        $logger->log($_SESSION['user_id'], 'DELETE_DUPLICATE_FAIL', "Deleted document ID $id from DB but failed to delete file; restored record.");
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log("DELETE_DUPLICATE_ERROR: " . $e->getMessage());
                    $msg = "❌ Error deleting duplicate record. Please try again later.";
                }
            }
        }
    }

    // --- ZIP ARCHIVE & COMPRESS OLD FILES ---
    if (isset($_POST['archive_old_vault'])) {
        $months = (int)$_POST['months_old'];
        if ($months < 1) $months = 12; // default 1 year

        $cutoffDate = date('Y-m-d H:i:s', strtotime("-$months months"));

        // Find docs older than cutoff date
        $stmt = $pdo->prepare("SELECT id, file_path, original_name, category FROM documents WHERE uploaded_at < ? AND deleted_at IS NULL");
        $stmt->execute([$cutoffDate]);
        $docsToArchive = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($docsToArchive)) {
            $msg = "ℹ️ No active files older than $months months found to compress.";
        } else {
            $backupDir = realpath(__DIR__ . '/../backups');
            if (!$backupDir) {
                @mkdir(__DIR__ . '/../backups', 0755, true);
                $backupDir = realpath(__DIR__ . '/../backups');
            }

            $zipFile = $backupDir . '/Vault_Archive_' . $months . 'MonthsOld_' . date('Ymd_His') . '.zip';
            $zip = new ZipArchive();

            if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {
                $archivedCount = 0;
                $freedSpace = 0;
                $idsToSoftDelete = [];

                foreach ($docsToArchive as $doc) {
                    $fullPath = $vaultPath . basename($doc['file_path']);
                    if (file_exists($fullPath)) {
                        $freedSpace += filesize($fullPath);
                        // Organize cleanly inside the ZIP by category
                        $zipName = preg_replace('/[^a-zA-Z0-9\-\._]/', '_', $doc['category']) . '/' . basename($doc['original_name']);
                        $zip->addFile($fullPath, $zipName);
                        $archivedCount++;
                        $idsToSoftDelete[] = $doc['id'];
                    }
                }
                $zip->close();

                if ($archivedCount > 0) {
                    // Soft delete from DB to hide from active views
                    $placeholders = implode(',', array_fill(0, count($idsToSoftDelete), '?'));
                    $pdo->prepare("UPDATE documents SET deleted_at = NOW(), description = CONCAT(COALESCE(description, ''), ' [Compressed to ZIP]') WHERE id IN ($placeholders)")->execute($idsToSoftDelete);

                    // Delete physical files to free active vault space
                    foreach ($docsToArchive as $doc) {
                        if (in_array($doc['id'], $idsToSoftDelete)) {
                            @unlink($vaultPath . basename($doc['file_path']));
                        }
                    }

                    $freedMB = round($freedSpace / 1024 / 1024, 2);
                    $msg = "✅ Successfully compressed $archivedCount old files into a ZIP archive, freeing up $freedMB MB of vault space. The archive is stored in your 'backups' folder.";
                    $logger = new Logger($pdo);
                    $logger->log($_SESSION['user_id'], 'VAULT_COMPRESS', "Zipped and removed $archivedCount files older than $months months ($freedMB MB freed).");
                } else {
                    $msg = "❌ Failed to archive files. Files might be missing on disk.";
                }
            } else {
                $msg = "❌ Could not create ZIP archive.";
            }
        }
    }

    // --- MASTER SYNC ---
    if (isset($_POST['master_sync'])) {
        $logMessages = [];

        // 1. Fix Dashboard Count: Remove documents for non-existent employees
        $sql = "DELETE d FROM documents d LEFT JOIN employees e ON d.employee_id = e.emp_id WHERE e.id IS NULL AND d.employee_id != 'RECOVERED'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $ghosts = $stmt->rowCount();
        if ($ghosts > 0) $logMessages[] = "Removed $ghosts DB records for missing employees.";

        // 1.5 Fix Disciplinary Cases for non-existent employees
        $sql = "DELETE d FROM disciplinary_cases d LEFT JOIN employees e ON d.employee_id = e.emp_id WHERE e.id IS NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        // 2. Fix Dashboard Count: Remove documents where the file is missing
        $stmt = $pdo->query("SELECT id, file_path, category FROM documents");
        $broken = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $path = $vaultPath . $row['file_path'];
            if ($row['category'] === 'Disciplinary') $path = __DIR__ . '/uploads/' . $row['file_path'];

            if (!file_exists($path)) {
                $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$row['id']]);
                $broken++;
            }
        }
        if ($broken > 0) $logMessages[] = "Removed $broken DB records pointing to missing files.";

        clearstatcache();

        // 3. Fix Vault Storage: Remove files not in DB
        $dbFiles = $pdo->query("SELECT file_path FROM documents")->fetchAll(PDO::FETCH_COLUMN);
        $discFiles = $pdo->query("SELECT attachment_path FROM disciplinary_cases WHERE attachment_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        $validFiles = array_merge($dbFiles, $discFiles);

        // [LOGICAL FIX] Prevent deleting files currently waiting in Pending Requests
        $reqStmt = $pdo->query("SELECT json_payload FROM requests WHERE request_type = 'UPLOAD_DOC' AND status = 'PENDING'");
        while ($reqRow = $reqStmt->fetch(PDO::FETCH_ASSOC)) {
            $payload = json_decode($reqRow['json_payload'], true);
            if (!empty($payload['file_path'])) $validFiles[] = $payload['file_path'];
        }

        $validFiles = array_map('basename', $validFiles); // Ensure we only compare filenames
        $validFiles[] = 'manifest_DO_NOT_DELETE.txt';
        $validFiles[] = '.gitkeep';
        $validFiles[] = '.htaccess';
        $validFiles[] = 'tesp-logo-1.png';
        $validFiles[] = 'tesp logo 1.png';

        $diskFiles = array_diff(scandir($vaultPath), ['.', '..']);
        $orphans = 0;
        foreach ($diskFiles as $f) {
            if (!in_array($f, $validFiles)) {
                $fullPath = $vaultPath . $f;
                if (is_file($fullPath)) {
                    @chmod($fullPath, 0666); // Try to fix permissions
                    if (@unlink($fullPath)) $orphans++;
                }
            }
        }
        if ($orphans > 0) $logMessages[] = "Deleted $orphans orphaned files from Vault.";

        // 4. Fix Uploads Folder (Disciplinary & Avatars)
        $uploadsPath = __DIR__ . '/uploads/';
        if (is_dir($uploadsPath)) {
            $upFiles = array_diff(scandir($uploadsPath), ['.', '..']);
            $upOrphans = 0;
            // Valid files in uploads: Disciplinary attachments + System assets
            $validUploads = $discFiles;
            // Add documents that might be in uploads (legacy/fallback)
            foreach ($dbFiles as $dbf) $validUploads[] = $dbf;

            $validUploads = array_map('basename', $validUploads);
            $validUploads = array_merge($validUploads, ['index.php', '.htaccess', 'tesp-logo-1.png', 'tesp logo 1.png', 'avatars', '.gitkeep']);

            foreach ($upFiles as $f) {
                if (is_dir($uploadsPath . $f)) continue;
                if (!in_array($f, $validUploads)) {
                    @unlink($uploadsPath . $f);
                    $upOrphans++;
                }
            }
            if ($upOrphans > 0) $logMessages[] = "Deleted $upOrphans orphaned files from Uploads.";
        }

        // 5. Fix Avatars
        $avatarsPath = __DIR__ . '/uploads/avatars/';
        if (is_dir($avatarsPath)) {
            $avFiles = array_diff(scandir($avatarsPath), ['.', '..']);
            $validAvatars = $pdo->query("SELECT avatar_path FROM employees WHERE avatar_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            $validAvatars[] = 'default.png';

            // [LOGICAL FIX] Prevent deleting avatars currently waiting in Pending Requests
            $reqStmt = $pdo->query("SELECT json_payload FROM requests WHERE request_type IN ('ADD_EMPLOYEE', 'EDIT_PROFILE') AND status = 'PENDING'");
            while ($reqRow = $reqStmt->fetch(PDO::FETCH_ASSOC)) {
                $payload = json_decode($reqRow['json_payload'], true);
                if (!empty($payload['avatar_path'])) $validAvatars[] = $payload['avatar_path'];
            }

            foreach ($avFiles as $f) {
                if (!in_array($f, $validAvatars)) @unlink($avatarsPath . $f);
            }
        }

        $msg = empty($logMessages) ? "✅ System is already in sync." : "✅ Sync Complete: " . implode(" ", $logMessages);
    }

    // --- RESTORE EMPLOYEE ---
    if (isset($_POST['restore_employee'])) {
        $empId = $_POST['emp_id'];
        $pdo->prepare("UPDATE employees SET deleted_at = NULL WHERE id = ?")->execute([$empId]);
        $msg = "✅ Employee restored successfully.";
        $logger = new Logger($pdo);
        $logger->log($_SESSION['user_id'], 'RESTORE_EMPLOYEE', "Restored employee ID $empId");
    }

    // --- PERMANENT DELETE EMPLOYEE ---
    if (isset($_POST['permanent_delete_employee'])) {
        $empId = $_POST['emp_id'];
        $empIdStr = $_POST['emp_id_str'];

        try {
            $pdo->beginTransaction();

            // 1. Delete Physical Documents
            $stmt = $pdo->prepare("SELECT file_path FROM documents WHERE employee_id = ?");
            $stmt->execute([$empIdStr]);
            $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($files as $file) {
                $fullPath = $vaultPath . $file;
                if (file_exists($fullPath)) @unlink($fullPath);
            }

            // 2. Delete Disciplinary Files
            $stmt = $pdo->prepare("SELECT attachment_path FROM disciplinary_cases WHERE employee_id = ?");
            $stmt->execute([$empIdStr]);
            $discFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($discFiles as $file) {
                $fullPath = __DIR__ . '/uploads/' . $file;
                if (file_exists($fullPath)) @unlink($fullPath);
            }

            // 2.5 Delete Avatar
            $stmt = $pdo->prepare("SELECT avatar_path FROM employees WHERE id = ?");
            $stmt->execute([$empId]);
            $avatar = $stmt->fetchColumn();
            if ($avatar && basename($avatar) !== 'default.png') {
                $avatar = basename($avatar);
                $avPath = __DIR__ . '/uploads/avatars/' . $avatar;
                if (file_exists($avPath) && !unlink($avPath)) {
                    error_log("Failed to delete avatar: $avPath");
                }
            }

            // 3. Delete DB Records
            $pdo->prepare("DELETE FROM documents WHERE employee_id = ?")->execute([$empIdStr]);
            $pdo->prepare("DELETE FROM performance_evaluations WHERE employee_id = ?")->execute([$empId]);
            $pdo->prepare("DELETE FROM hr_performance_reviews WHERE employee_id = ?")->execute([$empId]);
            $pdo->prepare("DELETE FROM disciplinary_cases WHERE employee_id = ?")->execute([$empIdStr]);
            $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$empId]);

            $pdo->commit();

            $msg = "🗑️ Employee and all data permanently deleted.";
            $logger->log($_SESSION['user_id'], 'PERMANENT_DELETE_EMPLOYEE', "Permanently deleted $empIdStr");
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("PERMANENT_DELETE_ERROR: " . $e->getMessage());
            $msg = "❌ Error deleting employee. Please try again later.";
        }
    }

    // --- PRUNE BROKEN LINKS (Fix Charts) ---
    if (isset($_POST['prune_broken_links'])) {
        $idsToPrune = json_decode($_POST['broken_list'], true);
        if (is_array($idsToPrune)) {
            // Normalize and validate IDs
            $idsToPrune = array_values(array_filter(array_map(function ($id) {
                return is_numeric($id) ? (int)$id : 0;
            }, $idsToPrune), function ($id) {
                return $id > 0;
            }));
        }

        if (!empty($idsToPrune)) {
            // Delete records where file is missing
            $placeholders = implode(',', array_fill(0, count($idsToPrune), '?'));
            $stmt = $pdo->prepare("DELETE FROM documents WHERE id IN ($placeholders)"); // Placeholders are safe here as they are generated by array_fill
            $stmt->execute($idsToPrune);
            $count = $stmt->rowCount();

            $msg = "🧹 Pruned $count broken database records. Your charts should now be accurate.";
            $logger = new Logger($pdo);
            $logger->log($_SESSION['user_id'], 'PRUNE_DB', "Deleted $count document records with missing files.");
        }
    }

    // --- DELETE SINGLE BROKEN LINK ---
    if (isset($_POST['delete_broken_link'])) {
        $id = $_POST['doc_id'];
        $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$id]);
        $msg = "🗑️ Deleted 1 broken database record.";
        $logger = new Logger($pdo);
        $logger->log($_SESSION['user_id'], 'PRUNE_DB_SINGLE', "Deleted document record ID $id");
    }

    // --- PRUNE GHOST RECORDS (Documents for non-existent employees) ---
    if (isset($_POST['prune_ghosts'])) {
        // Handle both JSON from hidden field and array from checkboxes
        $idsToPrune = [];

        // First try to get from ghost_ids_json hidden field
        if (!empty($_POST['ghost_ids_json'])) {
            $idsToPrune = json_decode($_POST['ghost_ids_json'], true);
        }

        // Fallback to ghost_ids[] array if present
        if (empty($idsToPrune) && !empty($_POST['ghost_ids'])) {
            $idsToPrune = array_map(function ($id) {
                return (int)$id;
            }, $_POST['ghost_ids']);
        }
        if (is_array($idsToPrune) && !empty($idsToPrune)) {
            // 1. Delete physical files first
            $placeholders = implode(',', array_fill(0, count($idsToPrune), '?'));
            $stmt = $pdo->prepare("SELECT file_path, category FROM documents WHERE id IN ($placeholders)");
            $stmt->execute($idsToPrune);
            $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($files as $f) {
                $p = $vaultPath . basename($f['file_path']);
                // [FIX] Check uploads folder if not in vault (e.g. Disciplinary)
                // Also handle cases where file_path might include 'uploads/' prefix already
                $cleanName = basename($f['file_path']);
                if (!file_exists($p)) {
                    $alt = __DIR__ . '/uploads/' . $cleanName;
                    if (file_exists($alt)) $p = $alt;
                }
                if (file_exists($p)) @unlink($p);
            }

            // 2. Delete DB records
            $stmt = $pdo->prepare("DELETE FROM documents WHERE id IN ($placeholders)");
            $stmt->execute($idsToPrune);
            $count = $stmt->rowCount();

            $msg = "👻 Pruned $count ghost document records and their files.";
            $logger->log($_SESSION['user_id'], 'PRUNE_GHOSTS', "Deleted $count documents for non-existent employees.");
        }
    }

    // --- RESTORE GHOST EMPLOYEE (From Audit Logs) ---
    if (isset($_POST['restore_ghost'])) {
        $ghostId = $_POST['ghost_emp_id'];
        // 1. Search Activity Logs for the deletion snapshot
        $stmt = $pdo->prepare("SELECT details FROM activity_logs WHERE action = 'DELETE_EMPLOYEE' AND details LIKE ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(["%Deleted: $ghostId | DATA: %"]);
        $logEntry = $stmt->fetchColumn();

        if ($logEntry && preg_match('/DATA: (.*)$/', $logEntry, $matches)) {
            // 2. Found snapshot, try to restore
            $data = json_decode($matches[1], true);
            if ($data) {
                try {
                    // Whitelist columns to prevent injection via malformed log data
                    $allowedColumns = [
                        'id',
                        'emp_id',
                        'first_name',
                        'middle_name',
                        'last_name',
                        'job_title',
                        'system_role',
                        'dept',
                        'section',
                        'employment_type',
                        'agency_name',
                        'company_name',
                        'previous_company',
                        'hire_date',
                        'gender',
                        'birth_date',
                        'contact_number',
                        'email',
                        'present_address',
                        'permanent_address',
                        'sss_no',
                        'tin_no',
                        'pagibig_no',
                        'philhealth_no',
                        'emergency_name',
                        'emergency_contact',
                        'emergency_address',
                        'education',
                        'experience',
                        'skills',
                        'licenses',
                        'status',
                        'exit_date',
                        'exit_reason',
                        'avatar_path',
                        'import_batch',
                        'last_reminded',
                        'created_at',
                        'updated_at',
                        'deleted_at'
                    ];
                    $filteredData = array_intersect_key($data, array_flip($allowedColumns));

                    if (empty($filteredData)) {
                        throw new Exception('No valid employee columns found in backup data');
                    }

                    $cols = array_keys($filteredData);
                    $colsStr = implode("`, `", $cols);
                    $valsStr = implode(", ", array_fill(0, count($cols), "?"));
                    $stmt = $pdo->prepare("INSERT INTO employees (`$colsStr`) VALUES ($valsStr)");
                    $stmt->execute(array_values($filteredData));

                    $msg = "✅ Magic Restore: Employee '$ghostId' recovered from audit logs!";
                    $logger = new Logger($pdo);
                    $logger->log($_SESSION['user_id'], 'RESTORE_GHOST', "Restored $ghostId from logs.");
                } catch (Exception $e) {
                    $msg = "❌ Restore failed (ID might be taken): " . $e->getMessage();
                }
            }
        } else {
            // 3. No log found, redirect to manual add
            header("Location: add_employee.php?emp_id=" . urlencode($ghostId) . "&msg=" . urlencode("⚠️ No backup found in logs. Please re-add details manually."));
            exit;
        }
    }
}

// 3. SCAN FOR ORPHANED FILES
// Files that exist in /vault/ but NOT in the database
$dbFiles = $pdo->query("SELECT file_path FROM documents")->fetchAll(PDO::FETCH_COLUMN);

// [NEW] Also fetch known Disciplinary files (in uploads/) and Avatars
$discFiles = $pdo->query("SELECT attachment_path FROM disciplinary_cases WHERE attachment_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$avatarFiles = $pdo->query("SELECT avatar_path FROM employees WHERE avatar_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

// [LOGICAL FIX] Include Pending Files so they aren't falsely flagged as orphans in the UI
$reqStmt = $pdo->query("SELECT request_type, json_payload FROM requests WHERE status = 'PENDING'");
while ($reqRow = $reqStmt->fetch(PDO::FETCH_ASSOC)) {
    $payload = json_decode($reqRow['json_payload'], true);
    if ($reqRow['request_type'] === 'UPLOAD_DOC' && !empty($payload['file_path'])) {
        $dbFiles[] = $payload['file_path'];
    } elseif (in_array($reqRow['request_type'], ['ADD_EMPLOYEE', 'EDIT_PROFILE']) && !empty($payload['avatar_path'])) {
        $avatarFiles[] = $payload['avatar_path'];
    }
}

$allKnownFiles = array_merge($dbFiles, $discFiles);

// [SECURITY] Guard clause for scandir() return value
$scanResult = scandir($vaultPath);
if ($scanResult === false) {
    $msg = "❌ Error: Unable to scan vault directory.";
    $scanResult = [];
}

$diskFiles = array_diff($scanResult, ['.', '..']);
$orphans = [];

foreach ($diskFiles as $f) {
    // Skip if file is in database or is the manifest
    if (in_array($f, $allKnownFiles) || $f === 'manifest_DO_NOT_DELETE.txt' || $f === '.gitkeep' || $f === '.htaccess' || $f === 'tesp-logo-1.png' || $f === 'tesp logo 1.png') continue;

    $file_path = $vaultPath . $f;

    if (file_exists($file_path)) {
        $file_size = round(filesize($file_path) / 1024, 2) . ' KB';
        $file_date = date('M d, Y H:i', filemtime($file_path));
    } else {
        $file_size = 'Unknown';
        $file_date = 'Unknown';
    }
    $orphans[] = ['name' => $f, 'size' => $file_size, 'date' => $file_date];
}

// [NEW] Scan public/uploads/ for orphaned Disciplinary Files
$uploadsPath = __DIR__ . '/uploads/';
if (is_dir($uploadsPath)) {
    $upScan = scandir($uploadsPath);
    foreach ($upScan as $f) {
        if ($f === '.' || $f === '..' || is_dir($uploadsPath . $f)) continue;
        // Skip known files and system assets
        if (in_array($f, $allKnownFiles) || $f === 'index.php' || $f === '.htaccess' || $f === 'tesp-logo-1.png' || $f === 'tesp logo 1.png' || $f === '.gitkeep') continue;

        $file_path = $uploadsPath . $f;
        $orphans[] = [
            'name' => 'uploads/' . $f,
            'size' => round(filesize($file_path) / 1024, 2) . ' KB',
            'date' => date('M d, Y H:i', filemtime($file_path))
        ];
    }
}

// [NEW] Scan public/uploads/avatars/ for orphaned Photos
$avatarsPath = __DIR__ . '/uploads/avatars/';
if (is_dir($avatarsPath)) {
    $avScan = scandir($avatarsPath);
    foreach ($avScan as $f) {
        if ($f === '.' || $f === '..' || $f === 'default.png' || $f === 'tesp-logo-1.png' || $f === 'tesp logo 1.png') continue;
        if (in_array($f, $avatarFiles)) continue;

        $file_path = $avatarsPath . $f;
        $orphans[] = [
            'name' => 'uploads/avatars/' . $f,
            'size' => round(filesize($file_path) / 1024, 2) . ' KB',
            'date' => date('M d, Y H:i', filemtime($file_path))
        ];
    }
}

// 4. FETCH SOFT-DELETED EMPLOYEES
$deletedEmployees = [];
try {
    // [NEW] Fetch deleter username from activity logs
    $sql = "SELECT e.*, 
            (SELECT u.username 
             FROM activity_logs a 
             JOIN users u ON a.user_id = u.id 
             WHERE a.action = 'SOFT_DELETE_EMPLOYEE' 
               AND a.details LIKE CONCAT('%', e.emp_id, '%') 
             ORDER BY a.created_at DESC LIMIT 1
            ) as deleted_by_user
            FROM employees e 
            WHERE e.deleted_at IS NOT NULL 
            ORDER BY e.deleted_at DESC";
    $stmt = $pdo->query($sql);
    $deletedEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Column might not exist yet
}

// 5. SCAN FOR BROKEN LINKS (DB records pointing to missing files)
$brokenLinks = [];
$allDbDocs = $pdo->query("SELECT id, file_path, original_name, category FROM documents")->fetchAll(PDO::FETCH_ASSOC);
foreach ($allDbDocs as $doc) {
    $checkPath = $vaultPath . $doc['file_path'];

    // [FIX] Disciplinary files are stored in public/uploads/, not vault/
    if ($doc['category'] === 'Disciplinary') {
        $checkPath = __DIR__ . '/uploads/' . $doc['file_path'];
    }

    if (!file_exists($checkPath)) {
        $brokenLinks[] = $doc;
    }
}

// 6. SCAN FOR GHOST RECORDS (Documents pointing to non-existent employees)
$ghostRecords = [];
$ghostSql = "SELECT d.id, d.original_name, d.employee_id, d.file_path 
             FROM documents d 
             LEFT JOIN employees e ON TRIM(d.employee_id) = TRIM(e.emp_id) 
             WHERE e.id IS NULL AND d.employee_id != 'RECOVERED'";
$ghostRecords = $pdo->query($ghostSql)->fetchAll(PDO::FETCH_ASSOC);

// 7. SCAN FOR DUPLICATE UPLOADS (Same Employee, Same Name, Same Category)
$duplicates = $pdo->query("
    SELECT d.id, d.employee_id, d.original_name, d.category, d.uploaded_at, e.first_name, e.last_name
    FROM documents d
    JOIN (
        SELECT employee_id, original_name, category
        FROM documents
        WHERE deleted_at IS NULL
        GROUP BY employee_id, original_name, category
        HAVING COUNT(*) > 1
    ) dup ON d.employee_id = dup.employee_id 
         AND d.original_name = dup.original_name 
         AND d.category = dup.category
    LEFT JOIN employees e ON d.employee_id = e.emp_id
    WHERE d.deleted_at IS NULL
    ORDER BY d.employee_id, d.original_name, d.uploaded_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// [NEW] Fetch default vault setting for checkboxes
$bkVaultSetting = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_include_vault'")->fetchColumn();
$vaultChecked = ($bkVaultSetting === '1') ? 'checked' : '';
$bkMaxSize = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'backup_max_size_gb'")->fetchColumn() ?: '1.9';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>System Recovery Console</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="assets/images/tesp-logo-1.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-light">

    <nav class="navbar navbar-dark bg-danger mb-4">
        <div class="container">
            <a class="navbar-brand" href="manager_user.php">⬅ Back to User Manager</a>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-light fw-bold" onclick="downloadRestorationGuide()"><i class="bi bi-file-earmark-text"></i> Restoration Guide</button>
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white fw-bold"><i class="bi bi-tools"></i> System Recovery Console</span>
                <span class="navbar-text text-white-50 ms-3 font-monospace small"><i class="bi bi-clock"></i> <span id="sessionTimer"></span></span>
            </div>
        </div>
    </nav>

    <div class="container">

        <?php if ($msg): ?>
            <div class="alert alert-info alert-dismissible fade show">
                <?php echo htmlspecialchars($msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-4" id="recoveryTabs">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#orphans">👻 Orphaned Files (<?php echo count($orphans); ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#deleted">🗑️ Deleted Employees (<?php echo count($deletedEmployees); ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#broken">⚠️ Broken Links (<?php echo count($brokenLinks); ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#ghosts">🧟 Ghost Records (<?php echo count($ghostRecords); ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#duplicates">👯 Duplicates (<?php echo count($duplicates); ?>)</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#compress">🗜️ Storage Optimization</button></li>
        </ul>

        <div class="tab-content">

            <!-- ORPHANED FILES TAB -->
            <div class="tab-pane fade show active" id="orphans">
                <div class="alert alert-info d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-info-circle-fill"></i> <strong>Master Sync:</strong> Run this to fix dashboard counts and clean the vault in one go.</span>
                    <form method="POST" onsubmit="return confirm('WARNING: This will delete ALL orphaned files and broken database records. Ensure you have a backup first. Proceed?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <button type="submit" name="master_sync" class="btn btn-primary fw-bold"><i class="bi bi-arrow-repeat"></i> Run Master Sync</button>
                    </form>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-warning text-dark">
                        <i class="bi bi-file-earmark-x"></i> <strong>Orphaned Files</strong>
                        <small class="d-block text-muted">Files on server but missing from database.</small>
                        <?php if (!empty($orphans)): ?>
                            <div class="mt-2">
                                <button type="button" onclick="submitBulkOrphans()" class="btn btn-sm btn-danger fw-bold me-2">🗑️ Delete Selected</button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('WARNING: This will permanently delete ALL listed orphaned files. This cannot be undone. Proceed?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="orphan_list" value="<?php echo htmlspecialchars(json_encode(array_column($orphans, 'name'))); ?>">
                                    <button type="submit" name="delete_all_orphans" class="btn btn-sm btn-outline-danger">Delete ALL</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 40px;"><input type="checkbox" class="form-check-input" id="selectAllOrphans"></th>
                                    <th>Filename</th>
                                    <th>Size</th>
                                    <th>Date Modified</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orphans)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center p-4 text-muted">✅ No orphaned files found. System is in sync.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orphans as $o): ?>
                                        <tr>
                                            <td><input type="checkbox" value="<?php echo htmlspecialchars($o['name']); ?>" class="form-check-input orphan-checkbox"></td>
                                            <td class="font-monospace small"><?php echo htmlspecialchars($o['name']); ?></td>
                                            <td><?php echo $o['size']; ?></td>
                                            <td><?php echo $o['date']; ?></td>
                                            <td>
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="filename" value="<?php echo htmlspecialchars($o['name']); ?>">
                                                    <?php if (strpos($o['name'], 'uploads/') === false): ?>
                                                        <button type="submit" name="recover_file" class="btn btn-sm btn-success">
                                                            <i class="bi bi-recycle"></i> Recover to DB
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="submit" name="delete_orphan" class="btn btn-sm btn-danger ms-1" onclick="return confirm('Permanently delete this file? This cannot be undone.');">
                                                        <i class="bi bi-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- DELETED EMPLOYEES TAB -->
            <div class="tab-pane fade" id="deleted">
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white">
                        <i class="bi bi-person-x"></i> <strong>Recycle Bin: Employees</strong>
                        <small class="d-block text-light">Restore employees or permanently delete them (including files).</small>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Date Deleted</th>
                                    <th>Name</th>
                                    <th>ID</th>
                                    <th>Department</th>
                                    <th>Deleted By</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deletedEmployees as $emp): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y h:i A', strtotime($emp['deleted_at'])); ?></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($emp['last_name'] . ', ' . $emp['first_name']); ?></td>
                                        <td><?php echo htmlspecialchars($emp['emp_id']); ?></td>
                                        <td><?php echo htmlspecialchars($emp['dept']); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($emp['deleted_by_user'] ?? 'Unknown'); ?></span></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="emp_id" value="<?php echo $emp['id']; ?>">
                                                <button type="submit" name="restore_employee" class="btn btn-sm btn-success"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('⚠️ PERMANENTLY DELETE? This will wipe the database record AND all uploaded files. This cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="emp_id" value="<?php echo $emp['id']; ?>">
                                                <input type="hidden" name="emp_id_str" value="<?php echo htmlspecialchars($emp['emp_id']); ?>">
                                                <button type="submit" name="permanent_delete_employee" class="btn btn-sm btn-outline-danger ms-1"><i class="bi bi-x-lg"></i> Delete Forever</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($deletedEmployees)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center p-4 text-muted">No deleted employees found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- BROKEN LINKS TAB -->
            <div class="tab-pane fade" id="broken">
                <div class="card shadow-sm">
                    <div class="card-header bg-danger text-white">
                        <i class="bi bi-link-45deg"></i> <strong>Broken Database Links</strong>
                        <small class="d-block text-white-50">These records exist in the database, but the actual files are missing from the server. This causes incorrect charts.</small>
                        <?php if (!empty($brokenLinks)): ?>
                            <form method="POST" class="mt-2" onsubmit="return confirm('This will delete these records from the database to fix your charts. Proceed?');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="broken_list" value="<?php echo htmlspecialchars(json_encode(array_column($brokenLinks, 'id'))); ?>">
                                <button type="submit" name="prune_broken_links" class="btn btn-sm btn-light text-danger fw-bold">🧹 Prune Database Records</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Document Name</th>
                                    <th>Category</th>
                                    <th>Missing File Path</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($brokenLinks)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center p-4 text-muted">✅ No broken links found. Database is consistent.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($brokenLinks as $b): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($b['original_name']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($b['category']); ?></span></td>
                                            <td class="text-muted small font-monospace"><?php echo htmlspecialchars($b['file_path']); ?></td>
                                            <td>
                                                <form method="POST" onsubmit="return confirm('Delete this record?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="doc_id" value="<?php echo $b['id']; ?>">
                                                    <button type="submit" name="delete_broken_link" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- GHOST RECORDS TAB -->
            <div class="tab-pane fade" id="ghosts">
                <div class="card shadow-sm">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-person-dash"></i> <strong>Ghost Records</strong>
                            <small class="d-block text-white-50">These documents exist, but the Employee they belong to has been deleted.</small>
                        </div>
                        <?php if (!empty($ghostRecords)): ?>
                            <button type="button" onclick="submitPruneGhosts()" class="btn btn-sm btn-danger fw-bold">🧟 Prune Selected</button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 40px;"><input type="checkbox" class="form-check-input" id="selectAllGhosts"></th>
                                    <th>Document Name</th>
                                    <th>Missing Employee ID</th>
                                    <th>File Path</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($ghostRecords)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center p-4 text-muted">✅ No ghost records found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($ghostRecords as $g): ?>
                                        <tr>
                                            <td><input type="checkbox" value="<?php echo $g['id']; ?>" class="form-check-input ghost-checkbox"></td>
                                            <td><?php echo htmlspecialchars($g['original_name']); ?></td>
                                            <td><span class="badge bg-danger"><?php echo htmlspecialchars($g['employee_id']); ?></span></td>
                                            <td class="text-muted small"><?php echo htmlspecialchars($g['file_path']); ?></td>
                                            <td>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="ghost_emp_id" value="<?php echo htmlspecialchars($g['employee_id']); ?>">
                                                    <button type="submit" name="restore_ghost" class="btn btn-sm btn-outline-success" title="Attempt to restore from logs">
                                                        <i class="bi bi-magic"></i> Restore
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- DUPLICATES TAB -->
            <div class="tab-pane fade" id="duplicates">
                <div class="card shadow-sm">
                    <div class="card-header bg-info text-white">
                        <i class="bi bi-files"></i> <strong>Duplicate Uploads</strong>
                        <small class="d-block text-white-50">Documents with the same Name and Category for the same Employee.</small>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Employee</th>
                                    <th>Document Name</th>
                                    <th>Category</th>
                                    <th>Uploaded At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($duplicates)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center p-4 text-muted">✅ No duplicate uploads found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($duplicates as $d): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($d['last_name'] . ', ' . $d['first_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($d['employee_id']); ?></small>
                                            </td>
                                            <td class="fw-bold"><?php echo htmlspecialchars($d['original_name']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($d['category']); ?></span></td>
                                            <td class="small"><?php echo $d['uploaded_at'] ? date('M d, Y h:i A', strtotime($d['uploaded_at'])) : 'Unknown'; ?></td>
                                            <td>
                                                <form method="POST" onsubmit="return confirm('Delete this duplicate?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="doc_id" value="<?php echo $d['id']; ?>">
                                                    <button type="submit" name="delete_duplicate" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- COMPRESS VAULT TAB -->
            <div class="tab-pane fade" id="compress">
                <div class="card shadow-sm border-warning">
                    <div class="card-header bg-warning text-dark">
                        <i class="bi bi-file-zip"></i> <strong>Compress & Archive Old Documents</strong>
                        <small class="d-block text-muted">Move old, inactive documents out of the Vault into a highly compressed ZIP archive to save active disk space.</small>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle-fill"></i> <strong>How this works:</strong> This tool will package documents older than your selected timeframe into a ZIP file in the <code>backups/</code> folder. The original files will be deleted from the Vault, freeing up space, and their database records will be soft-deleted (moved to the Recycle Bin).
                        </div>
                        <form method="POST" onsubmit="return confirm('WARNING: This will remove old files from active employee profiles and archive them. This action is intended for saving disk space. Proceed?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="row align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">Age of Documents to Archive</label>
                                    <select name="months_old" class="form-select">
                                        <option value="12">Older than 1 Year (12 months)</option>
                                        <option value="24">Older than 2 Years (24 months)</option>
                                        <option value="36">Older than 3 Years (36 months)</option>
                                        <option value="60">Older than 5 Years (60 months)</option>
                                        <option value="6">Older than 6 Months</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" name="archive_old_vault" class="btn btn-warning fw-bold w-100"><i class="bi bi-file-zip-fill"></i> Compress & Archive</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- HIDDEN FORM FOR BULK PRUNE -->
    <form id="pruneGhostsForm" method="POST" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="prune_ghosts" value="1">
        <input type="hidden" name="ghost_ids_json" id="hidden_ghost_ids">
    </form>

    <!-- HIDDEN FORM FOR BULK ORPHANS -->
    <form id="bulkOrphansForm" method="POST" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="bulk_delete_orphans" value="1">
        <input type="hidden" name="orphan_list_json" id="hidden_orphan_list">
    </form>

    <!-- MODALS FOR BACKUP -->
    <div class="modal fade" id="downloadBackupModal" tabindex="-1">
        <div class="modal-dialog">
            <form action="backup.php" method="POST" class="modal-content" onsubmit="showBackupLoader(this)">
                <div class="modal-header">
                    <h5 class="modal-title">Download Database Backup</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="include_vault" value="1" id="dlVault" <?php echo $vaultChecked; ?>>
                        <label class="form-check-label fw-bold" for="dlVault">Include Vault Files (Images/PDFs)</label>
                        <div class="alert alert-warning small mb-0 mt-2 border-warning">
                            <i class="bi bi-info-circle-fill"></i> <strong>Massive Data Reminder:</strong> If your backup exceeds the <strong><?php echo htmlspecialchars($bkMaxSize); ?> GB</strong> limit, the system will automatically split it into multiple volumes (Part 1, Part 2, etc.) and download them consecutively.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password (Optional)</label>
                        <input type="password" name="backup_password" class="form-control" placeholder="Leave blank for unencrypted SQL" maxlength="50" autocomplete="new-password">
                        <div class="form-text">Creates a password-protected ZIP file.</div>
                    </div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-primary">Download</button></div>
            </form>
        </div>
    </div>
    <div class="modal fade" id="serverBackupModal" tabindex="-1">
        <div class="modal-dialog">
            <form action="backup.php?mode=server" method="POST" class="modal-content" onsubmit="showBackupLoader(this)">
                <div class="modal-header">
                    <h5 class="modal-title">Save Backup to Server</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <p>This will save a backup to the configured server paths. This is recommended for automated recovery.</p>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="include_vault" value="1" id="svVault" <?php echo $vaultChecked; ?>>
                        <label class="form-check-label fw-bold" for="svVault">Include Vault Files (Images/PDFs)</label>
                        <div class="form-text text-muted mt-1" style="font-size: 0.75rem;">
                            <i class="bi bi-info-circle"></i> Note: When saving to the server, Vault files are mirrored, not zipped.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password (Optional)</label>
                        <input type="password" name="backup_password" class="form-control" placeholder="Leave blank for unencrypted SQL" maxlength="50" autocomplete="new-password">
                        <div class="form-text">Creates a password-protected ZIP file on the server.</div>
                    </div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-danger">Save to Server</button></div>
            </form>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="dark_mode.js"></script>
    <script>
        // Select-all checkbox for ghost records
        document.addEventListener('DOMContentLoaded', function() {
            const selectAllCheckbox = document.getElementById('selectAllGhosts');
            const ghostCheckboxes = document.querySelectorAll('.ghost-checkbox');

            const selectAllOrphans = document.getElementById('selectAllOrphans');
            const orphanCheckboxes = document.querySelectorAll('.orphan-checkbox');

            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    ghostCheckboxes.forEach(cb => {
                        cb.checked = this.checked;
                    });
                });
            }

            if (selectAllOrphans) {
                selectAllOrphans.addEventListener('change', function() {
                    orphanCheckboxes.forEach(cb => {
                        cb.checked = this.checked;
                    });
                });
            }
        });

        function submitPruneGhosts() {
            const checked = document.querySelectorAll('.ghost-checkbox:checked');
            if (checked.length === 0) {
                alert("Please select at least one record to prune.");
                return;
            }
            if (!confirm(`Permanently delete ${checked.length} ghost records and their files? This cannot be undone.`)) {
                return;
            }

            const ids = Array.from(checked).map(cb => parseInt(cb.value));
            document.getElementById('hidden_ghost_ids').value = JSON.stringify(ids);
            document.getElementById('pruneGhostsForm').submit();
        }

        function submitBulkOrphans() {
            const checked = document.querySelectorAll('.orphan-checkbox:checked');
            if (checked.length === 0) {
                alert("Please select at least one file to delete.");
                return;
            }
            if (!confirm(`Permanently delete ${checked.length} orphaned files? This cannot be undone.`)) {
                return;
            }

            const files = Array.from(checked).map(cb => cb.value);
            document.getElementById('hidden_orphan_list').value = JSON.stringify(files);
            document.getElementById('bulkOrphansForm').submit();
        }

        // [SECURITY] Auto-Logout Timer
        const timeoutDuration = <?php echo $clientTimeout * 1000; ?>;
        let timeLeft = timeoutDuration;

        function updateTimer() {
            timeLeft -= 1000;
            if (timeLeft <= 0) window.location.href = 'logout.php';
            const m = Math.floor(timeLeft / 60000);
            const s = Math.floor((timeLeft % 60000) / 1000);
            document.getElementById('sessionTimer').innerText = `${m}:${s.toString().padStart(2, '0')}`;
        }
        document.addEventListener('mousemove', () => timeLeft = timeoutDuration);
        document.addEventListener('keypress', () => timeLeft = timeoutDuration);
        setInterval(updateTimer, 1000);
        updateTimer();

        function showBackupLoader(form) {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                const originalHtml = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Working...';
                const csrf = form.querySelector('[name="csrf_token"]').value;
                let pollCount = 0;
                const maxPollAttempts = 300; // 5 minutes at 1s intervals
                const checkCookie = setInterval(() => {
                    pollCount++;
                    if (document.cookie.includes('downloadToken=' + csrf)) {
                        clearInterval(checkCookie);
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalHtml;
                        document.cookie = "downloadToken=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                    } else if (pollCount >= maxPollAttempts) {
                        clearInterval(checkCookie);
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalHtml;
                    }
                }, 1000);
            }
            return true;
        }

        const guideText = `RESTORATION GUIDE\n\nOption 1: Database Restore (Automatic)\nUse this to roll back data changes (e.g. accidental deletion).\n1. Locate a backup in the Available Auto-Backups list.\n2. Click the Restore This button.\n3. Enter your Admin Password to confirm.\n\nOption 2: Full System Recovery (Manual & Split ZIPs)\nUse this if the server crashed, you moved to a new PC, or you have a multi-part backup.\n1. Database: Under "2. System Restore", click the "Choose Files" button.\n2. Upload: Browse to your backup file. If your backup is split into multiple parts (e.g., Part1.zip, Part2.zip), highlight and select ALL of them at the exact same time.\n3. Confirm: Type in your Admin Password and click "Restore Database". The server will automatically organize the parts, silently unpack the SQL inside them, and reconstruct your entire database!\n4. Documents (Vault):\n   Note: The ZIP files above only restore the database records.\n   - If your backup included Vault Files, open the ZIP file manually on your computer.\n   - Extract the 'vault' folder from the ZIP.\n   - Paste it into your server's directory: C:\\xampp\\htdocs\\hr 201\\vault\\\n5. Encryption Key: Ensure config/config.php is restored if lost, as it contains your secure Vault Key.`;

        function downloadRestorationGuide() {
            const blob = new Blob([guideText], {
                type: "text/plain;charset=utf-8"
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.href = url;
            a.download = "HR_System_Restoration_Guide.txt";
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Guide downloaded successfully!',
                    showConfirmButton: false,
                    timer: 2000
                });
            }
        }
    </script>
</body>

</html>