<?php
// ======================================================
// [FILE] public/manage_options.php
// [PURPOSE] Manage Dynamic Dropdown Options & Role Duties
// ======================================================

require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// [FIX] Include global helper functions
require_once __DIR__ . '/../src/helpers.php';

$userRole = strtoupper(trim($_SESSION['role'] ?? ''));

// 1. SECURITY: Admin and Manager Only
$security = new Security($pdo);
$logger   = new Logger($pdo);

// [SECURITY] CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 2. AUTO-INIT DATABASE TABLES & SEEDING
try {
    // A. Agencies
    $pdo->exec("CREATE TABLE IF NOT EXISTS agencies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        duties TEXT DEFAULT NULL,
        name VARCHAR(100) NOT NULL UNIQUE
    )");
    $stmtAgencies = $pdo->query("SELECT COUNT(*) FROM agencies");
    $agencyCount = $stmtAgencies ? $stmtAgencies->fetchColumn() : false;
    if ($agencyCount !== false && (int)$agencyCount == 0) {
        $defaults = ["TESP DIRECT", "GUNJIN", "JORATECH", "UNLISOLUTIONS", "OTHERS - SUBCONS"];
        $stmt = $pdo->prepare("INSERT INTO agencies (name) VALUES (?)");
        foreach ($defaults as $d) try {
            $stmt->execute([$d]);
        } catch (Exception $e) {
        }
    }

    // B. System Roles
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        duties TEXT DEFAULT NULL
    )");

    // [AUTO-UPGRADE] Duties column check
    try {
        $pdo->query("SELECT duties FROM system_roles LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE system_roles ADD COLUMN duties TEXT DEFAULT NULL");
    }

    $checkRoles = $pdo->query("SELECT COUNT(*) FROM system_roles");
    $roleCount = $checkRoles ? $checkRoles->fetchColumn() : 0;

    if ((int)$roleCount == 0) {
        $defaults = ["Manager", "Head", "Advisor", "Engineer", "Technician", "Officer", "IT", "Driver", "Staff", "Maintenance"];
        $stmt = $pdo->prepare("INSERT INTO system_roles (name) VALUES (?)");
        foreach ($defaults as $d) try {
            $stmt->execute([$d]);
        } catch (Exception $e) {
        }
    }

    // C. Departments
    $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    )");

    // D. Sections
    $pdo->exec("CREATE TABLE IF NOT EXISTS sections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        department_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
        UNIQUE KEY unique_section (department_id, name)
    )");

    // Seed Departments & Sections if empty
    $checkDepts = $pdo->query("SELECT COUNT(*) FROM departments");
    $deptsCount = $checkDepts ? $checkDepts->fetchColumn() : 0;

    if ((int)$deptsCount == 0) {
        $seedMap = [
            "SQP"     => ["GENERAL", "SAFETY", "QA", "PLANNING", "IT"],
            "ADMIN"   => ["GENERAL", "GAG", "TKG", "PCG", "ACG", "MED", "CLEANERS"],
            "OP"      => ["OFFICE OF THE PRESIDENT"],
            "SIGCOM"  => ["SIGNALING & COMMUNICATION"],
            "PSS"     => ["POWER SUPPLY SECTION"],
            "OCS"     => ["OVERHEAD CATENARY SYSTEM"],
            "MHI"     => ["MITSUBISHI HEAVY INDUSTRIES"],
            "HMS"     => ["HEAVY MAINTENANCE SECTION"],
            "RAS"     => ["ROOT CAUSE ANALYSIS"],
            "TRS"     => ["TECHNICAL RESEARCH SECTION"],
            "LMS"     => ["LIGHT MAINTENANCE SECTION"],
            "DOS"     => ["GENERAL", "CCRE", "SHUNTER", "DOS_OFF", "GEN_SUP"],
            "CTS"     => ["CIVIL TRACKS SECTION"],
            "BFS"     => ["GENERAL", "DEPOT_EQ", "CONVEY", "MOTOR"],
            "WHS"     => ["WAREHOUSE SECTION"],
            "GUNJIN"  => ["EMT", "SECURITY"],
            "SUBCONS-OTHERS" => ["OTHERS"]
        ];

        $deptStmt = $pdo->prepare("INSERT INTO departments (name) VALUES (?)");
        $sectStmt = $pdo->prepare("INSERT INTO sections (department_id, name) VALUES (?, ?)");

        foreach ($seedMap as $dept => $sections) {
            try {
                $deptStmt->execute([$dept]);
                $deptId = $pdo->lastInsertId();
                foreach ($sections as $sect) {
                    try {
                        $sectStmt->execute([$deptId, $sect]);
                    } catch (Exception $e) {
                    }
                }
            } catch (Exception $e) {
            }
        }
    }

    // CC. Groups
    $pdo->exec("CREATE TABLE IF NOT EXISTS groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE
    )");
    $checkGroups = $pdo->query("SELECT COUNT(*) FROM groups");
    if ($checkGroups && (int)$checkGroups->fetchColumn() == 0) {
        $stmt = $pdo->prepare("INSERT INTO groups (name) VALUES (?)");
        foreach (['GROUP A', 'GROUP B', 'GROUP C'] as $g) {
            try {
                $stmt->execute([$g]);
            } catch (PDOException $e) {
                // Ignore duplicate inserts and continue seeding
            }
        }
    }

    // E. Disciplinary Violations
    $pdo->exec("CREATE TABLE IF NOT EXISTS disciplinary_violations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(50) NOT NULL,
        name VARCHAR(100) NOT NULL,
        description TEXT NULL,
        UNIQUE KEY unique_viol (category, name)
    )");
    $checkViol = $pdo->query("SELECT COUNT(*) FROM disciplinary_violations");
    $violCount = $checkViol ? $checkViol->fetchColumn() : 0;

    if ((int)$violCount == 0) {
        $vDefaults = [
            "Attendance" => ["Tardiness / Late", "AWOL (Absence Without Leave)", "Abandonment of Work", "Undertime"],
            "Conduct"    => ["Insubordination", "Disrespect to Superior", "Fighting / Assault", "Gambling on Premises"],
            "Honesty"    => ["Dishonesty", "Falsification of Records", "Theft", "Fraud"],
            "Safety"     => ["LSR Violation", "Non-use of PPE", "Unsafe Act", "Safety Negligence"],
            "Performance" => ["Negligence of Duty", "Sleeping on Duty", "Malingering", "Poor Work Performance"]
        ];
        $stmt = $pdo->prepare("INSERT INTO disciplinary_violations (category, name) VALUES (?, ?)");
        foreach ($vDefaults as $cat => $items) {
            foreach ($items as $item) try {
                $stmt->execute([$cat, $item]);
            } catch (Exception $e) {
            }
        }
    }

    // F. Company Rules
    $pdo->exec("CREATE TABLE IF NOT EXISTS company_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT NULL
    )");
    $checkRules = $pdo->query("SELECT COUNT(*) FROM company_rules");
    $rulesCount = $checkRules ? $checkRules->fetchColumn() : 0;

    if ((int)$rulesCount == 0) {
        $rDefaults = ["Rule I - Attendance and Punctuality", "Rule II - Conduct and Decorum", "Rule III - Safety and Health", "Rule IV - Company Property", "Rule V - Honesty and Integrity", "Rule VI - General Provisions", "Project-Specific Safety Protocol", "Data Privacy Policy"];
        $stmt = $pdo->prepare("INSERT INTO company_rules (name) VALUES (?)");
        foreach ($rDefaults as $r) try {
            $stmt->execute([$r]);
        } catch (Exception $e) {
        }
    }

    // G. Training Catalog
    $pdo->exec("CREATE TABLE IF NOT EXISTS courses_catalog (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        category VARCHAR(50) NOT NULL,
        provider VARCHAR(100) NULL,
        validity_months INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // H. College Courses
    $pdo->exec("CREATE TABLE IF NOT EXISTS college_courses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        course_name VARCHAR(100) NOT NULL UNIQUE,
        keywords TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // [AUTO-UPGRADE] Add keywords column if missing
    $chkKeys = $pdo->query("SHOW COLUMNS FROM college_courses LIKE 'keywords'");
    if ($chkKeys->rowCount() == 0) {
        $pdo->exec("ALTER TABLE college_courses ADD COLUMN keywords TEXT DEFAULT NULL AFTER course_name");
    }

    // [SECURITY] Runtime schema alterations are intentionally not performed here.
    // These pages should not issue DDL on every request; use explicit migrations
    // or admin maintenance scripts instead so restricted DB users are not blocked.
} catch (PDOException $e) {
    die("Database Initialization Error: " . $e->getMessage());
}

// 3. HANDLE ACTIONS
$msg = "";
$error = "";
$redirectMsg = "";
$activeTab = 'agency';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid CSRF Token";
    } else {
        $action = $_POST['action'] ?? '';
        $name   = trim($_POST['name'] ?? '');
        $id     = (int)($_POST['id'] ?? 0);

        // Keep the active tab open
        if (strpos($action, 'agency') !== false) {
            $activeTab = 'agency';
        } elseif (strpos($action, 'role') !== false) {
            $activeTab = 'role';
        } elseif (strpos($action, 'college') !== false) {
            $activeTab = 'college';
        } elseif (strpos($action, 'course') !== false) {
            $activeTab = 'course';
        } elseif (strpos($action, 'group') !== false) {
            $activeTab = 'group';
        } elseif (strpos($action, 'dept') !== false || strpos($action, 'section') !== false) {
            $activeTab = 'dept';
        } elseif (strpos($action, 'violation') !== false) {
            $activeTab = 'violation';
        } elseif (strpos($action, 'rule') !== false) {
            $activeTab = 'rule';
        }

        // --- AGENCIES ---
        if (empty($error) && $action === 'add_agency' && !empty($name)) {
            $name = strtoupper($name);
            try {
                if (strlen($name) > 100) $error = "❌ Agency name is too long (Max 100 chars).";
                elseif (!preg_match('/^[A-Z0-9\s\-\.]+$/', $name)) $error = "❌ Agency name contains invalid characters.";
                else {
                    $stmt = $pdo->prepare("INSERT INTO agencies (name) VALUES (?)");
                    $stmt->execute([$name]);
                    $logger->log($_SESSION['user_id'], 'ADD_AGENCY', "Added agency: $name");
                    $redirectMsg = "✅ Agency '$name' added successfully.";
                }
            } catch (PDOException $e) {
                $error = "❌ Error: Agency name already exists.";
            }
        } elseif (empty($error) && $action === 'edit_agency' && !empty($name) && $id > 0) {
            $name = strtoupper($name);
            try {
                if (strlen($name) > 100) $error = "❌ Agency name is too long (Max 100 chars).";
                elseif (!preg_match('/^[A-Z0-9\s\-\.]+$/', $name)) $error = "❌ Agency name contains invalid characters.";
                else {
                    $stmt = $pdo->prepare("UPDATE agencies SET name = ? WHERE id = ?");
                    $stmt->execute([$name, $id]);
                    $logger->log($_SESSION['user_id'], 'EDIT_AGENCY', "Updated agency ID $id to $name");
                    $redirectMsg = "✅ Agency updated successfully.";
                }
            } catch (PDOException $e) {
                $error = "❌ Error: Name already taken.";
            }
        } elseif ($action === 'delete_agency' && $id > 0) {

            $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE agency_name = (SELECT name FROM agencies WHERE id = ?)");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: There are employees assigned to this agency.";
            } else {
                $pdo->prepare("DELETE FROM agencies WHERE id = ?")->execute([$id]);
                $logger->log($_SESSION['user_id'], 'DELETE_AGENCY', "Deleted agency ID $id");
                $redirectMsg = "✅ Agency deleted.";
            }
        }

        // --- ROLES ---
        elseif (empty($error) && $action === 'add_role' && !empty($name)) {
            // Allow Mixed Case for roles but sanitize
            try {
                if (strlen($name) > 100) $error = "❌ Role name is too long (Max 100 chars).";
                elseif (!preg_match('/^[A-Za-z0-9\s\-\.\&]+$/', $name)) $error = "❌ Role name contains invalid characters.";
                else {
                    $pdo->prepare("INSERT INTO system_roles (name) VALUES (?)")->execute([$name]);
                    $logger->log($_SESSION['user_id'], 'ADD_ROLE', "Added system role: $name");
                    $redirectMsg = "✅ Role added.";
                }
            } catch (Exception $e) {
                $error = "Role exists.";
            }
        } elseif ($action === 'delete_role' && $id > 0) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE system_role = (SELECT name FROM system_roles WHERE id = ?)");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: There are employees assigned to this role.";
            } else {
                $pdo->prepare("DELETE FROM system_roles WHERE id = ?")->execute([$id]);
                $logger->log($_SESSION['user_id'], 'DELETE_ROLE', "Deleted system role ID: $id");
                $redirectMsg = "✅ Role deleted.";
            }
        } elseif ($action === 'update_role_duties' && $id > 0) {
            $duties = trim($_POST['duties'] ?? '');
            if (strlen($duties) > 3000) {
                $error = "❌ Duties list is too long (Max 3000 characters).";
            } else {
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM system_roles WHERE id = ?");
                $checkStmt->execute([$id]);
                if ($checkStmt->fetchColumn() == 0) {
                    $error = "❌ Role not found.";
                } else {
                    $duties = strip_tags($duties);
                    $stmt = $pdo->prepare("UPDATE system_roles SET duties = ? WHERE id = ?");
                    $stmt->execute([$duties, $id]);
                    $logger->log($_SESSION['user_id'], 'EDIT_ROLE', "Updated duties for Role ID $id");
                    $redirectMsg = "✅ Role duties updated successfully.";
                }
            }
        }

        // --- COURSES ---
        elseif (empty($error) && $action === 'add_course' && !empty($name)) {
            $name = strtoupper($name);
            $cat  = strtoupper(trim($_POST['category'] ?? 'TECHNICAL'));
            $prov = trim($_POST['provider'] ?? '');
            $val  = (int)($_POST['validity'] ?? 0);

            // [SECURITY] Input Validation & Character Limits
            if (strlen($name) > 100 || !preg_match('/^[A-Z0-9\s\-\.\(\)\/]+$/', $name)) {
                $error = "❌ Invalid Course Name (Max 100 chars, Alphanumeric, dots, parens only).";
            } elseif (strlen($prov) > 100 || (!empty($prov) && !preg_match('/^[a-zA-Z0-9\s\-\.\,]+$/', $prov))) {
                $error = "❌ Invalid Provider (Max 100 chars, Alphanumeric and standard punctuation only).";
            } elseif (!in_array($cat, ['TECHNICAL', 'SAFETY', 'SOFT SKILLS', 'COMPLIANCE'])) {
                $error = "❌ Invalid Category selected.";
            } elseif ($val < 0 || $val > 120) {
                $error = "❌ Validity months must be between 0 and 120 (10 Years).";
            }

            if (empty($error)) {
                try {
                    $pdo->prepare("INSERT INTO courses_catalog (name, category, provider, validity_months) VALUES (?, ?, ?, ?)")
                        ->execute([$name, $cat, $prov, $val]);
                    $logger->log($_SESSION['user_id'], 'ADD_COURSE', "Added course: $name");
                    $redirectMsg = "✅ Course added to catalog.";
                } catch (Exception $e) {
                    $error = "❌ Course already exists in catalog.";
                }
            }
        } elseif ($action === 'delete_course' && $id > 0) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM employee_training WHERE course_id = ?");
            $chk->execute([$id]);
            if ($chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: Employees have records linked to this course.";
            } else {
                $pdo->prepare("DELETE FROM courses_catalog WHERE id = ?")->execute([$id]);
                $logger->log($_SESSION['user_id'], 'DELETE_COURSE', "Deleted course ID: $id");
                $redirectMsg = "✅ Course deleted.";
            }
        } elseif ($action === 'edit_course' && $id > 0) {
            $name = strtoupper($name);
            $cat  = strtoupper(trim($_POST['category'] ?? 'TECHNICAL'));
            $prov = trim($_POST['provider'] ?? '');
            $val  = (int)($_POST['validity'] ?? 0);

            // [SECURITY] Input Validation & Character Limits (Sync with add_course)
            if (strlen($name) > 100 || !preg_match('/^[A-Z0-9\s\-\.\(\)\/]+$/', $name)) {
                $error = "❌ Invalid Course Name (Max 100 chars, Alphanumeric, dots, parens only).";
            } elseif (strlen($prov) > 100 || (!empty($prov) && !preg_match('/^[a-zA-Z0-9\s\-\.\,]+$/', $prov))) {
                $error = "❌ Invalid Provider (Max 100 chars, Alphanumeric and standard punctuation only).";
            } elseif (!in_array($cat, ['TECHNICAL', 'SAFETY', 'SOFT SKILLS', 'COMPLIANCE'])) {
                $error = "❌ Invalid Category selected.";
            } elseif ($val < 0 || $val > 120) {
                $error = "❌ Validity months must be between 0 and 120.";
            }

            if (empty($error)) {
                try {
                    $pdo->prepare("UPDATE courses_catalog SET name = ?, category = ?, provider = ?, validity_months = ? WHERE id = ?")
                        ->execute([$name, $cat, $prov, $val, $id]);
                    $logger->log($_SESSION['user_id'], 'EDIT_COURSE', "Updated course ID $id to $name");
                    $redirectMsg = "✅ Course updated successfully.";
                } catch (Exception $e) {
                    $error = "Error updating course. Name might already be in use.";
                }
            }
        }

        // --- GROUPS ---
        elseif (empty($error) && $action === 'add_group' && !empty($name)) {
            $name = strtoupper($name);
            try {
                if (strlen($name) > 100) $error = "❌ Group name is too long.";
                elseif (!preg_match('/^[A-Z0-9\s\-\.\_]+$/', $name)) $error = "❌ Group name contains invalid characters.";
                else {
                    $pdo->prepare("INSERT INTO groups (name) VALUES (?)")->execute([$name]);
                    $logger->log($_SESSION['user_id'], 'ADD_GROUP', "Added group: $name");
                    $redirectMsg = "✅ Group added.";
                }
            } catch (Exception $e) {
                $error = "Group already exists.";
            }
        } elseif ($action === 'delete_group' && $id > 0) {
            try {
                $stmtName = $pdo->prepare("SELECT name FROM groups WHERE id = ?");
                $stmtName->execute([$id]);
                $gName = $stmtName->fetchColumn();

                if (!$gName) {
                    $error = "❌ Group not found.";
                } else {
                    // Check if name exists as a standalone word or in a comma list
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE `group` = ? OR `group` LIKE ? OR `group` LIKE ? OR `group` LIKE ?");
                    $chk->execute([$gName, "$gName, %", "%, $gName", "%, $gName, %"]);

                    if ($chk->fetchColumn() > 0) {
                        $error = "❌ Cannot delete: There are employees assigned to group '$gName'.";
                    } else {
                        $pdo->prepare("DELETE FROM groups WHERE id = ?")->execute([$id]);
                        $logger->log($_SESSION['user_id'], 'DELETE_GROUP', "Deleted group: $gName");
                        $redirectMsg = "✅ Group deleted.";
                    }
                }
            } catch (PDOException $e) {
                error_log('Group deletion failed for group ID ' . $id . ': ' . $e->getMessage());
                $error = "❌ Unable to delete the group right now. Please try again or contact an administrator.";
            }
        } elseif (empty($error) && $action === 'edit_group' && !empty($name) && $id > 0) {
            $name = strtoupper($name);
            try {
                if (strlen($name) > 100) $error = "❌ Group name is too long.";
                elseif (!preg_match('/^[A-Z0-9\s\-\.\_]+$/', $name)) $error = "❌ Group name contains invalid characters.";
                else {
                    $pdo->beginTransaction();
                    $old = $pdo->prepare("SELECT name FROM groups WHERE id = ?");
                    $old->execute([$id]);
                    $oldName = $old->fetchColumn();

                    $pdo->prepare("UPDATE groups SET name = ? WHERE id = ?")->execute([$name, $id]);

                    if ($oldName && $oldName !== $name) {
                        // Update existing employees who are part of this group (handles comma-separated lists)
                        $pdo->prepare(
                            "UPDATE employees SET `group` = TRIM(BOTH ', ' FROM REPLACE(CONCAT(', ', `group`, ', '), CONCAT(', ', ?, ', '), CONCAT(', ', ?, ', '))) WHERE CONCAT(', ', `group`, ', ') LIKE ?"
                        )->execute([$oldName, $name, "%, $oldName, %"]);
                    }
                    $pdo->commit();
                    $logger->log($_SESSION['user_id'], 'EDIT_GROUP', "Renamed group ID $id to $name");
                    $redirectMsg = "✅ Group renamed and employee records updated.";
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e->getCode() == 23000) {
                    $error = "❌ Group name already exists.";
                } else {
                    $error = "❌ DB Error during update: " . $e->getMessage();
                }
            }
        }

        // --- DEPARTMENTS ---
        elseif (empty($error) && $action === 'add_dept' && !empty($name)) {
            $name = strtoupper($name);
            try {
                if (strlen($name) > 50) $error = "❌ Department name is too long (Max 50 chars).";
                elseif (!preg_match('/^[A-Z0-9\s\-\.]+$/', $name)) $error = "❌ Department name contains invalid characters.";
                else {
                    $pdo->prepare("INSERT INTO departments (name) VALUES (?)")->execute([$name]);
                    $logger->log($_SESSION['user_id'], 'ADD_DEPT', "Added department: $name");
                    $redirectMsg = "✅ Department added.";
                }
            } catch (Exception $e) {
                $error = "Department exists.";
            }
        } elseif ($action === 'delete_dept' && $id > 0) {
            $stmtName = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
            $stmtName->execute([$id]);
            $dName = $stmtName->fetchColumn();

            // [FIX] Handle comma-separated dependency check
            $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE dept = ? OR dept LIKE ? OR dept LIKE ? OR dept LIKE ?");
            $chk->execute([$dName, "$dName, %", "%, $dName", "%, $dName, %"]);

            if ($chk && $chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: Employees are assigned to department '$dName'.";
            } else {
                try {
                    $pdo->prepare("DELETE FROM departments WHERE id = ?")->execute([$id]);
                    $logger->log($_SESSION['user_id'], 'DELETE_DEPT', "Deleted department ID: $id");
                    $redirectMsg = "✅ Department deleted.";
                } catch (Exception $e) {
                    $error = "❌ Delete failed: Ensure all sections are removed first.";
                }
            }
        } elseif (empty($error) && $action === 'edit_dept' && !empty($name) && $id > 0) {
            $name = strtoupper($name);
            try {
                if (strlen($name) > 50) $error = "❌ Department name is too long.";
                elseif (!preg_match('/^[A-Z0-9\s\-\.]+$/', $name)) $error = "❌ Invalid characters.";
                else {
                    $pdo->beginTransaction();
                    $old = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
                    $old->execute([$id]);
                    $oldName = $old->fetchColumn();
                    $pdo->prepare("UPDATE departments SET name = ? WHERE id = ?")->execute([$name, $id]);
                    if ($oldName && $oldName !== $name) {
                        // [FIX] Handle multi-select string rename
                        $pdo->prepare("UPDATE employees SET dept = TRIM(BOTH ', ' FROM REPLACE(CONCAT(', ', dept, ', '), CONCAT(', ', ?, ', '), CONCAT(', ', ?, ', '))) WHERE dept LIKE ?")
                            ->execute([$oldName, $name, "%$oldName%"]);
                    }
                    $pdo->commit();
                    $redirectMsg = "✅ Department renamed.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Name already taken.";
            }
        }

        // --- SECTIONS ---
        elseif (empty($error) && $action === 'add_section' && !empty($name)) {
            $name = strtoupper($name);
            $deptId = (int)$_POST['dept_id'];
            if ($deptId > 0) {
                try {
                    if (strlen($name) > 100) $error = "❌ Section name is too long (Max 100 chars).";
                    elseif (!preg_match('/^[A-Z0-9\s\-\.]+$/', $name)) $error = "❌ Section name contains invalid characters.";
                    else {
                        $pdo->prepare("INSERT INTO sections (department_id, name) VALUES (?, ?)")->execute([$deptId, $name]);
                    }
                    $logger->log($_SESSION['user_id'], 'ADD_SECTION', "Added section '$name' to Dept ID: $deptId");
                    $redirectMsg = "✅ Section added.";
                } catch (Exception $e) {
                    $error = "Section exists in this department.";
                }
            }
        } elseif ($action === 'delete_section' && $id > 0) {
            $stmtName = $pdo->prepare("SELECT name FROM sections WHERE id = ?");
            $stmtName->execute([$id]);
            $sName = $stmtName->fetchColumn();

            $chk = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE section = ? OR section LIKE ? OR section LIKE ? OR section LIKE ?");
            $chk->execute([$sName, "$sName, %", "%, $sName", "%, $sName, %"]);

            if ($chk && $chk->fetchColumn() > 0) {
                $error = "❌ Cannot delete: Employees are assigned to section '$sName'.";
            } else {
                $pdo->prepare("DELETE FROM sections WHERE id = ?")->execute([$id]);
                $logger->log($_SESSION['user_id'], 'DELETE_SECTION', "Deleted section ID: $id");
                $redirectMsg = "✅ Section deleted.";
            }
        } elseif (empty($error) && $action === 'edit_section' && !empty($name) && $id > 0) {
            $name = strtoupper($name);
            try {
                if (strlen($name) > 100) $error = "❌ Section name too long.";
                elseif (!preg_match('/^[A-Z0-9\s\-\.]+$/', $name)) $error = "❌ Invalid characters.";
                else {
                    $pdo->beginTransaction();
                    $old = $pdo->prepare("SELECT name FROM sections WHERE id = ?");
                    $old->execute([$id]);
                    $oldName = $old->fetchColumn();
                    $pdo->prepare("UPDATE sections SET name = ? WHERE id = ?")->execute([$name, $id]);
                    if ($oldName && $oldName !== $name) {
                        $sectionUpdateStmt = $pdo->prepare("SELECT id, section FROM employees WHERE section = ? OR FIND_IN_SET(?, section) OR section LIKE ?");
                        $sectionUpdateStmt->execute([$oldName, $oldName, "%, $oldName%"]);
                        while ($row = $sectionUpdateStmt->fetch(PDO::FETCH_ASSOC)) {
                            $pieces = array_filter(array_map('trim', explode(',', $row['section'])), fn($v) => $v !== '');
                            $updated = [];
                            foreach ($pieces as $piece) {
                                $updated[] = ($piece === $oldName ? $name : $piece);
                            }
                            $updated = array_unique(array_filter($updated, fn($v) => $v !== ''));
                            $pdo->prepare("UPDATE employees SET section = ? WHERE id = ?")->execute([implode(', ', $updated), $row['id']]);
                        }
                    }
                    $pdo->commit();
                    $redirectMsg = "✅ Section renamed.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = "Name already exists in this dept.";
            }
        }

        // --- VIOLATIONS ---
        elseif ($action === 'add_violation' && !empty($name)) {
            $name = trim($name); // Violations can be mixed case
            $cat  = strtoupper(trim($_POST['category'] ?? 'GENERAL'));
            $desc = trim($_POST['description'] ?? '');

            if (strlen($cat) > 50 || !preg_match('/^[A-Z0-9\s\-\.]+$/', $cat)) $error = "❌ Category name is too long or contains invalid characters (Max 50 chars).";
            elseif (strlen($name) > 100 || !preg_match('/^[a-zA-Z0-9\s\-\.\,\(\)]+$/', $name)) $error = "❌ Violation name is too long or contains invalid characters (Max 100 chars).";
            elseif (strlen($desc) > 1000) $error = "❌ Description is too long (Max 1000 chars).";

            $chk = $pdo->prepare("SELECT id FROM disciplinary_violations WHERE name = ? AND category = ?");
            $chk->execute([$name, $cat]);
            if ($chk->fetch()) {
                $error = "❌ Violation '$name' already exists in category '$cat'.";
            }

            if (empty($error)) {
                $pdo->prepare("INSERT INTO disciplinary_violations (category, name, description) VALUES (?, ?, ?)")->execute([$cat, $name, $desc]);
                $redirectMsg = "✅ Violation added.";
            }
        } elseif ($action === 'delete_violation' && $id > 0) {
            $pdo->prepare("DELETE FROM disciplinary_violations WHERE id = ?")->execute([$id]);
            $redirectMsg = "✅ Violation removed.";
        } elseif ($action === 'edit_violation' && $id > 0) {
            $name = trim($name);
            $cat  = strtoupper(trim($_POST['category'] ?? ''));
            $desc = trim($_POST['description'] ?? '');

            if (strlen($cat) > 50) $error = "❌ Category name is too long.";
            elseif (strlen($name) > 100) $error = "❌ Violation name is too long.";
            elseif (strlen($desc) > 1000) $error = "❌ Description is too long (Max 1000 chars).";

            $chk = $pdo->prepare("SELECT id FROM disciplinary_violations WHERE name = ? AND category = ? AND id != ?");
            $chk->execute([$name, $cat, $id]);
            if ($chk->fetch()) {
                $error = "❌ Another violation with the name '$name' already exists in category '$cat'.";
            }

            if (empty($error)) {
                $pdo->prepare("UPDATE disciplinary_violations SET name = ?, category = ?, description = ? WHERE id = ?")->execute([$name, $cat, $desc, $id]);
                $redirectMsg = "✅ Violation updated.";
            }
        }

        // --- RULES ---
        elseif ($action === 'add_rule' && !empty($name)) {
            $name = trim($name);
            $desc = trim($_POST['description'] ?? '');
            if (strlen($name) > 100 || !preg_match('/^[a-zA-Z0-9\s\-\.\,\(\)]+$/', $name)) $error = "❌ Rule name is too long or contains invalid characters (Max 100 chars).";
            elseif (strlen($desc) > 2000) $error = "❌ Rule description is too long (Max 2000 chars).";

            $chk = $pdo->prepare("SELECT id FROM company_rules WHERE name = ?");
            $chk->execute([$name]);
            if ($chk->fetch()) {
                $error = "❌ Rule '$name' already exists.";
            }

            if (empty($error)) {
                $pdo->prepare("INSERT INTO company_rules (name, description) VALUES (?, ?)")->execute([$name, $desc]);
                $redirectMsg = "✅ Rule added.";
            }
        } elseif ($action === 'delete_rule' && $id > 0) {
            $pdo->prepare("DELETE FROM company_rules WHERE id = ?")->execute([$id]);
            $redirectMsg = "✅ Rule removed.";
        } elseif ($action === 'edit_rule' && $id > 0) {
            $name = trim($name);
            $desc = trim($_POST['description'] ?? '');
            if (strlen($name) > 100 || !preg_match('/^[a-zA-Z0-9\s\-\.\,\(\)]+$/', $name)) $error = "❌ Rule name is too long or contains invalid characters (Max 100 chars).";
            elseif (strlen($desc) > 2000) $error = "❌ Rule description is too long (Max 2000 chars).";

            $chk = $pdo->prepare("SELECT id FROM company_rules WHERE name = ? AND id != ?");
            $chk->execute([$name, $id]);
            if ($chk->fetch()) {
                $error = "❌ Another rule with the name '$name' already exists.";
            }

            if (empty($error)) {
                $pdo->prepare("UPDATE company_rules SET name = ?, description = ? WHERE id = ?")->execute([$name, $desc, $id]);
                $redirectMsg = "✅ Rule updated.";
            }
        }

        // --- COLLEGE COURSES ---
        elseif (empty($error) && $action === 'add_college_course' && !empty($name)) {
            $name = strtoupper($name);
            $keys = strtoupper(trim($_POST['keywords'] ?? ''));

            if (strlen($name) > 100) $error = "❌ Course name is too long (Max 100 chars).";
            elseif (strlen($keys) > 500) $error = "❌ Keywords are too long (Max 500 chars).";
            else {
                try {
                    $pdo->prepare("INSERT INTO college_courses (course_name, keywords) VALUES (?, ?)")->execute([$name, $keys]);
                    $logger->log($_SESSION['user_id'], 'ADD_COLLEGE_COURSE', "Added college course: $name");
                    $redirectMsg = "✅ College course added.";
                } catch (Exception $e) {
                    $error = "❌ Course already exists.";
                }
            }
        } elseif (empty($error) && $action === 'edit_college_course' && !empty($name) && $id > 0) {
            $name = strtoupper($name);
            $keys = strtoupper(trim($_POST['keywords'] ?? ''));

            if (strlen($name) > 100) $error = "❌ Course name is too long (Max 100 chars).";
            elseif (strlen($keys) > 500) $error = "❌ Keywords are too long (Max 500 chars).";
            else {
                try {
                    $stmt = $pdo->prepare("UPDATE college_courses SET course_name = ?, keywords = ? WHERE id = ?");
                    $stmt->execute([$name, $keys, $id]);
                    $logger->log($_SESSION['user_id'], 'EDIT_COLLEGE_COURSE', "Updated college course ID $id to $name");
                    $redirectMsg = "✅ College course updated successfully.";
                } catch (PDOException $e) {
                    $error = "❌ Error: Course name already exists.";
                }
            }
        } elseif ($action === 'delete_college_course' && $id > 0) {
            $pdo->prepare("DELETE FROM college_courses WHERE id = ?")->execute([$id]);
            $logger->log($_SESSION['user_id'], 'DELETE_COLLEGE_COURSE', "Deleted college course ID: $id");
            $redirectMsg = "✅ College course deleted.";
        }

        // Regenerate CSRF token on success
        if (!empty($redirectMsg)) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header("Location: manage_options.php?msg=" . urlencode($redirectMsg) . "&tab=" . urlencode($activeTab));
            exit;
        }
    }
}

// 4. FETCH DATA
$stmtAg = $pdo->query("SELECT * FROM agencies ORDER BY name ASC");
$agencies = $stmtAg ? $stmtAg->fetchAll() : [];

$stmtRo = $pdo->query("SELECT * FROM system_roles ORDER BY name ASC");
$roles = $stmtRo ? $stmtRo->fetchAll() : [];

$stmtDp = $pdo->query("SELECT * FROM departments ORDER BY name ASC");
$depts = $stmtDp ? $stmtDp->fetchAll() : [];

$stmtGr = $pdo->query("SELECT * FROM groups ORDER BY name ASC");
$groups = $stmtGr ? $stmtGr->fetchAll() : [];

$stmtVl = $pdo->query("SELECT * FROM disciplinary_violations ORDER BY category, name");
$vList = $stmtVl ? $stmtVl->fetchAll() : [];

$stmtRl = $pdo->query("SELECT * FROM company_rules ORDER BY name");
$rList = $stmtRl ? $stmtRl->fetchAll() : [];

// [FIX] Use safe query results to prevent 500 errors if tables were just created
$stmtCourses = $pdo->query("SELECT * FROM courses_catalog ORDER BY category, name");
$cList = $stmtCourses ? $stmtCourses->fetchAll() : [];
$stmtCollege = $pdo->query("SELECT * FROM college_courses ORDER BY course_name ASC");
$collegeCourses = $stmtCollege ? $stmtCollege->fetchAll() : [];

$sections = [];
$stmt = $pdo->query("SELECT s.id, s.name, s.department_id FROM sections s ORDER BY s.name ASC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $sections[$row['department_id']][] = $row;
}

if (isset($_GET['msg'])) $msg = $_GET['msg'];
if (isset($_GET['tab'])) $activeTab = $_GET['tab'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Manage Options</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="uploads/tesp-logo.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <script src="assets/sweetalert2.all.min.js"></script>
    <link rel="icon" type="image/png" href="../uploads/tesp-logo.png">
    <link rel="shortcut icon" type="image/png" href="../uploads/tesp-logo.png">
    <link rel="apple-touch-icon" href="../uploads/tesp-logo.png">
    <style>
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
</head>

<body class="bg-body-tertiary">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white"><i class="bi bi-list-check"></i> Manage Options</span>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <?php if ($msg): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-4" id="optionTabs" role="tablist">
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'agency' ? 'active' : ''; ?> fw-bold" id="agency-tab" data-bs-toggle="tab" data-bs-target="#agency" type="button">🏢 Agencies</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'role' ? 'active' : ''; ?> fw-bold" id="role-tab" data-bs-toggle="tab" data-bs-target="#role" type="button">💼 System Roles & Duties</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'college' ? 'active' : ''; ?> fw-bold" id="college-tab" data-bs-toggle="tab" data-bs-target="#college" type="button">🎓 College Courses</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'course' ? 'active' : ''; ?> fw-bold text-success" id="course-tab" data-bs-toggle="tab" data-bs-target="#course" type="button">🎓 Training Catalog</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'group' ? 'active' : ''; ?> fw-bold text-primary" id="group-tab" data-bs-toggle="tab" data-bs-target="#group" type="button">👥 Groups</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'dept' ? 'active' : ''; ?> fw-bold" id="dept-tab" data-bs-toggle="tab" data-bs-target="#dept" type="button">📂 Departments & Sections</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'violation' ? 'active' : ''; ?> fw-bold text-danger" id="violation-tab" data-bs-toggle="tab" data-bs-target="#violation" type="button">⚠️ Violations</button></li>
            <li class="nav-item"><button class="nav-link <?php echo $activeTab === 'rule' ? 'active' : ''; ?> fw-bold text-danger" id="rule-tab" data-bs-toggle="tab" data-bs-target="#rule" type="button">📜 Company Rules</button></li>
        </ul>

        <div class="tab-content" id="optionTabsContent">

            <div class="tab-pane fade <?php echo $activeTab === 'agency' ? 'show active' : ''; ?>" id="agency" role="tabpanel">
                <h4 class="mb-4">Agencies</h4>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <form method="POST" class="row g-2 mb-4 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_agency">
                            <div class="col-md-9">
                                <label class="form-label fw-bold">Add New Agency <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. NEW AGENCY INC." required maxlength="100" pattern="[A-Za-z0-9 \-\.]+" title="Alphanumeric, spaces, dashes, dots">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-lg"></i> Add</button>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Agency Name</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($agencies as $a): ?>
                                        <tr>
                                            <td>
                                                <form method="POST" class="d-flex gap-2">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="edit_agency">
                                                    <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                                                    <input type="text" name="name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($a['name']); ?>" required maxlength="100" pattern="[A-Za-z0-9 \-\.]+" title="Alphanumeric, spaces, dashes, dots">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-save"></i></button>
                                                </form>
                                            </td>
                                            <td class="text-end">
                                                <form method="POST" onsubmit="return confirm('Delete this agency?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="delete_agency">
                                                    <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?php echo $activeTab === 'college' ? 'show active' : ''; ?>" id="college" role="tabpanel">
                <h4 class="mb-4">College Courses</h4>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <form method="POST" class="row g-2 mb-4 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_college_course">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Add Standard College Course <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. BS COMPUTER SCIENCE" required maxlength="100">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-bold">Tags / Keywords (e.g. BSCS, IT) <span class="text-danger">*</span></label>
                                <div class="tag-container" id="new_college_tags" onclick="focusTagInput(this)">
                                    <input type="text" class="tag-input" placeholder="Type & Enter..." maxlength="255">
                                </div>
                                <input type="hidden" name="keywords" id="new_college_keys">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-lg"></i> Add</button>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Course Name</th>
                                        <th>Keywords</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($collegeCourses as $cc): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($cc['course_name']); ?></td>
                                            <td><small class="text-muted"><?php echo h($cc['keywords'] ?? 'None'); ?></small></td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-primary border-0 me-1"
                                                    onclick='editCollegeCourse(<?php echo $cc["id"]; ?>, <?php echo h(json_encode($cc["course_name"])); ?>, <?php echo h(json_encode($cc["keywords"] ?? "")); ?>)'>
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <form method="POST" onsubmit="return confirm('Delete this course suggestion?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="delete_college_course">
                                                    <input type="hidden" name="id" value="<?php echo $cc['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?php echo $activeTab === 'role' ? 'show active' : ''; ?>" id="role" role="tabpanel">
                <h4 class="mb-4">System Roles & Duties</h4>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <form method="POST" class="row g-2 mb-4 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_role">
                            <div class="col-md-9">
                                <label class="form-label fw-bold">Add New Role <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. SUPERVISOR" required maxlength="100" pattern="[A-Za-z0-9 \-\.]+" title="Alphanumeric, spaces, dashes, dots">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-lg"></i> Add</button>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 25%;">Role Name</th>
                                        <th>Contract Duties (Bullet Points)</th>
                                        <th style="width: 100px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($roles as $r): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($r['name']); ?></td>
                                            <td>
                                                <small class="text-muted d-block text-truncate" style="max-width: 400px;">
                                                    <?php echo !empty($r['duties']) ? str_replace("\n", " • ", substr($r['duties'], 0, 100)) . '...' : 'No duties defined.'; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-2">
                                                    <button type="button" class="btn btn-sm btn-primary"
                                                        data-role-id="<?php echo $r['id']; ?>"
                                                        data-role-name="<?php echo htmlspecialchars($r['name'], ENT_QUOTES); ?>"
                                                        data-role-duties="<?php echo htmlspecialchars($r['duties'] ?? '', ENT_QUOTES); ?>"
                                                        onclick="editDuties(this.dataset.roleId, this.dataset.roleName, this.dataset.roleDuties)">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                    <form method="POST" onsubmit="return confirm('Delete this role?');" class="m-0">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="action" value="delete_role">
                                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?php echo $activeTab === 'course' ? 'show active' : ''; ?>" id="course" role="tabpanel">
                <h4 class="mb-4">Training Catalog</h4>
                <div class="card shadow-sm border-success">
                    <div class="card-body">
                        <form method="POST" class="row g-2 mb-4 align-items-end p-3 bg-light border rounded">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_course">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Course Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Basic Safety Training" required maxlength="100" pattern="[a-zA-Z0-9\s\-\.\(\)\.]+" title="Alphanumeric, spaces, dots, parens, dashes">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                                <select name="category" class="form-select form-select-sm">
                                    <option value="TECHNICAL" selected>TECHNICAL</option>
                                    <option value="SAFETY">SAFETY</option>
                                    <option value="SOFT SKILLS">SOFT SKILLS</option>
                                    <option value="COMPLIANCE">COMPLIANCE</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Provider</label>
                                <input type="text" name="provider" class="form-control form-control-sm" placeholder="e.g. TESDA, Red Cross" maxlength="100" pattern="[a-zA-Z0-9\s\-\.\,]+" title="Alphanumeric, spaces, dashes, dots, commas">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-bold">Validity (Months)</label>
                                <input type="number" name="validity" class="form-control form-control-sm" value="0" min="0" max="999" oninput="if(this.value.length > 3) this.value = this.value.slice(0, 3);">
                            </div>
                            <div class="col-md-1">
                                <button type="submit" class="btn btn-success btn-sm w-100 fw-bold">Add</button>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Course Name</th>
                                        <th>Category</th>
                                        <th>Provider</th>
                                        <th>Validity</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cList as $c): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo h($c['name']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo h($c['category']); ?></span></td>
                                            <td><?php echo h($c['provider']); ?></td>
                                            <td><?php echo (int)$c['validity_months'] > 0 ? $c['validity_months'] . ' Mos' : 'Permanent'; ?></td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-primary border-0 me-1"
                                                    onclick='editCourse(<?php echo $c["id"]; ?>, <?php echo h(json_encode($c["name"])); ?>, <?php echo h(json_encode($c["category"])); ?>, <?php echo h(json_encode($c["provider"] ?? "")); ?>, <?php echo $c["validity_months"]; ?>)'>
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <form method="POST" onsubmit="return confirm('Delete this course?');" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="delete_course">
                                                    <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?php echo $activeTab === 'group' ? 'show active' : ''; ?>" id="group" role="tabpanel">
                <h4 class="mb-4">Groups</h4>
                <div class="card shadow-sm border-primary">
                    <div class="card-body">
                        <form method="POST" class="row g-2 mb-4 align-items-end p-3 bg-light border rounded">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_group">
                            <div class="col-md-9">
                                <label class="form-label fw-bold">Group Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" placeholder="e.g. GROUP D" required maxlength="100" pattern="[A-Za-z0-9 \-\.]+" title="Alphanumeric, spaces, dashes, dots">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100 fw-bold">Add Group</button>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Group Name</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($groups as $g): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo h($g['name']); ?></td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-primary border-0 me-1"
                                                    onclick='editGeneric("group", <?php echo $g["id"]; ?>, <?php echo h(json_encode($g["name"])); ?>)'>
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <form method="POST" onsubmit="return confirm('Delete this group?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="delete_group"><input type="hidden" name="id" value="<?php echo $g['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?php echo $activeTab === 'dept' ? 'show active' : ''; ?>" id="dept" role="tabpanel">
                <h4 class="mb-4">Departments & Sections</h4>
                <div class="row">
                    <div class="col-md-5">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-dark text-white">Departments</div>
                            <div class="card-body">
                                <form method="POST" class="input-group mb-3">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="add_dept">
                                    <input type="text" name="name" class="form-control" placeholder="New Dept" required maxlength="100" pattern="[A-Za-z0-9 \-\.]+" title="Alphanumeric, spaces, dashes, dots">
                                    <button class="btn btn-success" type="submit"><i class="bi bi-plus-lg"></i></button>
                                </form>
                                <div class="list-group" id="deptList">
                                    <?php foreach ($depts as $d): ?>
                                        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                            <a href="#" class="text-decoration-none text-dark flex-grow-1 py-1" onclick="showSections(<?php echo $d['id']; ?>, '<?php echo htmlspecialchars($d['name']); ?>'); return false;">
                                                <strong><?php echo htmlspecialchars($d['name']); ?></strong>
                                            </a>
                                            <div class="d-flex gap-1">
                                                <button type="button" class="btn btn-sm btn-outline-primary border-0"
                                                    onclick='editGeneric("dept", <?php echo $d["id"]; ?>, <?php echo h(json_encode($d["name"])); ?>)'>
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <form method="POST" onsubmit="return confirm('Delete Department? This will delete all its sections.');" class="m-0">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                    <input type="hidden" name="action" value="delete_dept">
                                                    <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-7">
                        <div class="card shadow-sm h-100">
                            <div class="card-header bg-secondary text-white d-flex justify-content-between">
                                <span id="sectTitle">Select a Department</span>
                            </div>
                            <div class="card-body">
                                <div id="sectContent" style="display:none;">
                                    <form method="POST" class="input-group mb-3">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="add_section">
                                        <input type="hidden" name="dept_id" id="activeDeptId">
                                        <input type="text" name="name" class="form-control" placeholder="New Section Name" required maxlength="100" pattern="[A-Za-z0-9 \-\.]+" title="Alphanumeric, spaces, dashes, dots">
                                        <button class="btn btn-success" type="submit"><i class="bi bi-plus-lg"></i> Add</button>
                                    </form>
                                    <ul class="list-group" id="sectList">
                                    </ul>
                                </div>
                                <div id="sectPlaceholder" class="text-muted text-center mt-5">
                                    <i class="bi bi-arrow-left-circle fs-1"></i><br>Click a department on the left to manage sections.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?php echo $activeTab === 'violation' ? 'show active' : ''; ?>" id="violation" role="tabpanel">
                <h4 class="mb-4">Violations</h4>
                <div class="card shadow-sm border-danger">
                    <div class="card-body">
                        <form method="POST" class="row g-2 mb-4 align-items-end p-3 bg-light border rounded">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_violation">
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                                <input type="text" name="category" class="form-control form-control-sm" placeholder="e.g. ATTENDANCE" required maxlength="50" pattern="[A-Za-z0-9\s\-\.]+" title="Alphanumeric, spaces, dashes, dots" oninput="this.value = this.value.toUpperCase()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Violation Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Excessive Tardiness" required maxlength="100" pattern="[a-zA-Z0-9\s\-\.\,\(\)]+" title="Alphanumeric, spaces, dots, parens, dashes, commas">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Policy Description</label>
                                <input type="text" name="description" class="form-control form-control-sm" placeholder="Optional details..." maxlength="1000">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-danger btn-sm w-100 fw-bold">Add Violation</button>
                            </div>
                        </form>
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>Category</th>
                                    <th>Violation</th>
                                    <th>Description</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vList as $v): ?>
                                    <tr>
                                        <td><span class="badge bg-secondary"><?php echo h($v['category']); ?></span></td>
                                        <td class="fw-bold"><?php echo h($v['name']); ?></td>
                                        <td class="small text-muted"><?php echo h($v['description']); ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this violation?');">
                                                <button type="button" class="btn btn-sm btn-outline-primary border-0 me-1"
                                                    onclick='editViolation(<?php echo $v["id"]; ?>, <?php echo h(json_encode($v["category"])); ?>, <?php echo h(json_encode($v["name"])); ?>, <?php echo h(json_encode($v["description"] ?? "")); ?>)'>
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete_violation"><input type="hidden" name="id" value="<?php echo $v['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?php echo $activeTab === 'rule' ? 'show active' : ''; ?>" id="rule" role="tabpanel">
                <h4 class="mb-4">Company Rules</h4>
                <div class="card shadow-sm border-danger">
                    <div class="card-body">
                        <form method="POST" class="row g-2 mb-4 align-items-end p-3 bg-light border rounded">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="add_rule">
                            <div class="col-md-5">
                                <label class="form-label fw-bold">Rule Name / Header <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Rule I - Section 1" required maxlength="100" pattern="[a-zA-Z0-9\s\-\.\,\(\)]+" title="Alphanumeric, spaces, dots, parens, dashes, commas">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-bold">Full Rule Description</label>
                                <input type="text" name="description" class="form-control form-control-sm" placeholder="Reference text from handbook..." maxlength="2000">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-danger btn-sm w-100 fw-bold">Add Rule</button>
                            </div>
                        </form>
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>Rule Name</th>
                                    <th>Reference Description</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rList as $r): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo h($r['name']); ?></td>
                                        <td class="small text-muted"><?php echo h($r['description']); ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this rule?');">
                                                <button type="button" class="btn btn-sm btn-outline-primary border-0 me-1"
                                                    onclick='editRule(<?php echo $r["id"]; ?>, <?php echo h(json_encode($r["name"])); ?>, <?php echo h(json_encode($r["description"] ?? "")); ?>)'>
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <div class="modal fade" id="dutiesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Edit Duties: <span id="modalRoleName" class="fw-bold"></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="update_role_duties">
                        <input type="hidden" name="id" id="modalRoleId">

                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle"></i> Enter each duty on a <strong>new line</strong>. These will appear as bullet points in the contract.
                        </div>
                        <textarea name="duties" id="modalDuties" class="form-control" rows="10" placeholder="e.g.&#10;Perform daily checks.&#10;Submit reports on time." maxlength="3000"></textarea>
                        <div class="form-text text-end">Max 3000 characters.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-info text-white" onclick="previewDuties()"><i class="bi bi-eye"></i> Preview</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Duties</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- GENERIC EDIT MODAL (Used for Dept, Section, Group) -->
    <div class="modal fade" id="genericEditModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Rename <span id="genericTypeLabel">Item</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" id="genericAction">
                    <input type="hidden" name="id" id="genericId">
                    <label class="form-label fw-bold">New Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="genericNameInput" class="form-control" required maxlength="100" pattern="[A-Za-z0-9\s\-\.\_]+" title="Alphanumeric, spaces, dots, dashes, underscores">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT COLLEGE COURSE MODAL -->
    <div class="modal fade" id="editCollegeCourseModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit College Course</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="edit_college_course">
                    <input type="hidden" name="id" id="editCollegeId">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Course Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editCollegeName" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Keywords (Comma Separated)</label>
                        <div class="tag-container" id="edit_college_tags" onclick="focusTagInput(this)">
                            <input type="text" class="tag-input" placeholder="Add tag...">
                        </div>
                        <input type="hidden" name="keywords" id="editCollegeKeys">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Course</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="editViolationModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Violation</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="edit_violation">
                    <input type="hidden" name="id" id="editViolId">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                        <input type="text" name="category" id="editViolCat" class="form-control" required maxlength="50" pattern="[A-Za-z0-9\s\-\.]+" title="Alphanumeric, spaces, dashes, dots">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Violation Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editViolName" class="form-control" required maxlength="100" pattern="[a-zA-Z0-9\s\-\.\,\(\)]+" title="Alphanumeric, spaces, dots, parens, dashes, commas">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Policy Description</label>
                        <textarea name="description" id="editViolDesc" class="form-control" rows="4" maxlength="1000"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Violation</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="editRuleModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Company Rule</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="edit_rule">
                    <input type="hidden" name="id" id="editRuleId">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Rule Name / Header <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editRuleName" class="form-control" required maxlength="100" pattern="[a-zA-Z0-9\s\-\.\,\(\)]+" title="Alphanumeric, spaces, dots, parens, dashes, commas">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Full Rule Description</label>
                        <textarea name="description" id="editRuleDesc" class="form-control" rows="6" maxlength="2000"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Rule</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="editCourseModal" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Course</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="edit_course">
                    <input type="hidden" name="id" id="editCourseId">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Course Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editCourseName" class="form-control" required maxlength="100" pattern="[a-zA-Z0-9\s\-\.\(\)\.]+" title="Alphanumeric, spaces, dots, parens, dashes">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                        <select name="category" id="editCourseCat" class="form-select">
                            <option value="TECHNICAL">TECHNICAL</option>
                            <option value="SAFETY">SAFETY</option>
                            <option value="SOFT SKILLS">SOFT SKILLS</option>
                            <option value="COMPLIANCE">COMPLIANCE</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Provider</label>
                        <input type="text" name="provider" id="editCourseProv" class="form-control" maxlength="100" pattern="[a-zA-Z0-9\s\-\.\,]+" title="Alphanumeric, spaces, dashes, dots, commas">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Validity (Months)</label>
                        <input type="number" name="validity" id="editCourseVal" class="form-control" min="0" max="999" oninput="if(this.value.length > 3) this.value = this.value.slice(0, 3);">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Course</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/bootstrap.bundle.min.js"></script>
    <script>
        const sections = <?php echo json_encode($sections); ?>;

        let dutiesModal;
        document.addEventListener("DOMContentLoaded", () => {
            dutiesModal = new bootstrap.Modal(document.getElementById('dutiesModal'));
        });

        function editDuties(id, name, currentDuties) {
            document.getElementById('modalRoleId').value = id;
            document.getElementById('modalRoleName').innerText = name;
            document.getElementById('modalDuties').value = currentDuties;
            dutiesModal.show();
        }

        function editViolation(id, cat, name, desc) {
            document.getElementById('editViolId').value = id;
            document.getElementById('editViolCat').value = cat;
            document.getElementById('editViolName').value = name;
            document.getElementById('editViolDesc').value = desc;
            new bootstrap.Modal(document.getElementById('editViolationModal')).show();
        }

        function editRule(id, name, desc) {
            document.getElementById('editRuleId').value = id;
            document.getElementById('editRuleName').value = name;
            document.getElementById('editRuleDesc').value = desc;
            new bootstrap.Modal(document.getElementById('editRuleModal')).show();
        }

        function editCourse(id, name, cat, prov, val) {
            document.getElementById('editCourseId').value = id;
            document.getElementById('editCourseName').value = name;
            document.getElementById('editCourseCat').value = cat;
            document.getElementById('editCourseProv').value = prov;
            document.getElementById('editCourseVal').value = val;
            new bootstrap.Modal(document.getElementById('editCourseModal')).show();
        }

        function editCollegeCourse(id, name, keywords) {
            document.getElementById('editCollegeId').value = id;
            document.getElementById('editCollegeName').value = name;
            document.getElementById('editCollegeKeys').value = keywords; // [FIX] Ensure keywords are passed
            initTags('edit_college_tags', 'editCollegeKeys');
            new bootstrap.Modal(document.getElementById('editCollegeCourseModal')).show();
        }

        function editGeneric(type, id, currentName) {
            document.getElementById('genericAction').value = 'edit_' + type;
            document.getElementById('genericId').value = id;
            document.getElementById('genericNameInput').value = currentName;
            document.getElementById('genericTypeLabel').innerText = type.charAt(0).toUpperCase() + type.slice(1);
            new bootstrap.Modal(document.getElementById('genericEditModal')).show();
        }

        // --- TAG SYSTEM LOGIC (Mirrored from tracker.php) ---
        function initTags(containerId, hiddenInputId) {
            const container = document.getElementById(containerId);
            const hiddenInput = document.getElementById(hiddenInputId);
            if (!container || !hiddenInput) return;
            const input = container.querySelector('.tag-input');
            const initialVal = hiddenInput.value;
            Array.from(container.querySelectorAll('.tag-chip')).forEach(el => el.remove());
            if (initialVal) {
                initialVal.split(',').map(s => s.trim()).filter(s => s).forEach(tag => {
                    addChip(container, tag);
                }); // [FIX] Add chip to container
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
            closeIcon.onclick = () => {
                chip.remove();
                updateHiddenInput(container);
            };
            chip.appendChild(label); // [FIX] Append label to chip
            chip.appendChild(closeIcon);
            container.insertBefore(chip, input);
        }

        function handleTagKey(e, input) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                const val = input.value.trim().toUpperCase().replace(/,/g, '');
                if (val && val.length <= 255) { // [FIX] Add length check for individual tags
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
            let hiddenId;
            if (container.id === 'new_college_tags') hiddenId = 'new_college_keys';
            else if (container.id === 'edit_college_tags') hiddenId = 'editCollegeKeys';
            const hidden = document.getElementById(hiddenId);
            if (hidden) hidden.value = values.join(', ');
        }
        document.addEventListener('DOMContentLoaded', () => {
            const newTagInput = document.querySelector('#new_college_tags .tag-input');
            if (newTagInput) newTagInput.onkeydown = (e) => handleTagKey(e, newTagInput);
            const editTagInput = document.querySelector('#edit_college_tags .tag-input');
            if (editTagInput) editTagInput.onkeydown = (e) => handleTagKey(e, editTagInput);
        });

        function previewDuties() {
            const text = document.getElementById('modalDuties').value;
            if (!text.trim()) {
                Swal.fire('Empty', 'No duties to preview.', 'info');
                return;
            }

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            const lines = text.split('\n').filter(line => line.trim() !== '');
            let html = '<ul class="text-start">';
            lines.forEach(line => {
                html += `<li>${escapeHtml(line)}</li>`;
            });
            html += '</ul>';

            Swal.fire({
                title: 'Contract Preview',
                html: html,
                icon: 'info',
                confirmButtonText: 'Close Preview'
            });
        }

        function showSections(deptId, deptName) {
            document.getElementById('sectTitle').innerText = 'Sections for: ' + deptName;
            document.getElementById('activeDeptId').value = deptId;
            document.getElementById('sectContent').style.display = 'block';
            document.getElementById('sectPlaceholder').style.display = 'none';

            const list = document.getElementById('sectList');
            list.innerHTML = '';

            const deptSections = sections[deptId] || [];
            if (deptSections.length === 0) {
                list.innerHTML = '<li class="list-group-item text-muted text-center">No sections found.</li>';
            } else {
                deptSections.forEach(s => {
                    const li = document.createElement('li');
                    li.className = 'list-group-item d-flex justify-content-between align-items-center';
                    // [FIX] Safe attribute escaping for dynamic JS strings
                    const safeName = s.name.replace(/'/g, "\\'").replace(/"/g, '&quot;');
                    const safeHtml = s.name.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                    li.innerHTML = `
                        <span class="fw-bold">${safeHtml}</span>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-primary border-0" onclick="editGeneric('section', ${s.id}, '${safeName}')">                                <i class="bi bi-pencil-square"></i>
                            </button>
                            <form method="POST" onsubmit="return confirm('Delete this section?');" class="m-0">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="delete_section">
                                <input type="hidden" name="id" value="${s.id}">
                                <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>`;
                    list.appendChild(li);
                });
            }
        }

        document.querySelectorAll('input[name="name"]').forEach(input => {
            const parentId = input.closest('.tab-pane')?.id;
            if (parentId !== 'agency' && parentId !== 'dept') return;
            input.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        });

        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // [NEW] Scroll Memory Logic
        const scrollKey = 'hr201_scroll_pos_' + window.location.pathname;
        window.addEventListener('beforeunload', () => {
            sessionStorage.setItem(scrollKey, window.scrollY);
        });

        const urlParamsForScroll = new URLSearchParams(window.location.search);
        if (urlParamsForScroll.has('msg') || urlParamsForScroll.has('tab')) {
            const savedPos = sessionStorage.getItem(scrollKey);
            if (savedPos) window.scrollTo(0, parseInt(savedPos));
        }
    </script>
    <script src="assets/dark_mode.js"></script>
</body>

</html>