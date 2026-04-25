<?php
// public/options.php

// Ensure DB connection
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
}

$sysOpts = [];

// 1. Fetch JSON Settings (with Fix for json_decode)
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // [FIX] json_decode failure returns null, which gets stored silently.
        // We check json_last_error() to ensure we only store valid decoded data.
        $decoded = json_decode($row['setting_value'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $sysOpts[$row['setting_key']] = $decoded;
        } else {
            // If it's not JSON (like plain text or numbers), store it as a standard string
            $sysOpts[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    // Ignore if table missing
}

// 2. Define Options (Prefer DB Tables if available, fallback to Settings/Defaults)

// Agencies
$agencies = [];
try {
    $stmt = $pdo->query("SELECT name FROM agencies ORDER BY name ASC");
    $agencies = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
}

if (empty($agencies)) {
    $agencies = $sysOpts['agencies'] ?? [
        'TESP DIRECT',
        'GUNJIN',
        'JORATECH',
        'UNLISOLUTIONS',
        'OTHERS - SUBCONS'
    ];
}

// System Roles
$system_roles = [];
try {
    $stmt = $pdo->query("SELECT name FROM system_roles ORDER BY name ASC");
    $system_roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
}

if (empty($system_roles)) {
    $system_roles = $sysOpts['system_roles'] ?? [
        'Manager',
        'Head',
        'Advisor',
        'Engineer',
        'Technician',
        'Officer',
        'IT',
        'Driver',
        'Staff',
        'Maintenance'
    ];
}

// Departments & Sections Map
$deptMap = [];
try {
    $dStmt = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC");
    while ($dRow = $dStmt->fetch(PDO::FETCH_ASSOC)) {
        $deptName = $dRow['name'];
        $sStmt = $pdo->prepare("SELECT name FROM sections WHERE department_id = ? ORDER BY name ASC");
        $sStmt->execute([$dRow['id']]);
        $deptMap[$deptName] = $sStmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
}

if (empty($deptMap)) {
    $deptMap = $sysOpts['dept_map'] ?? [
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
}

// [FIX] Section Friendly Map (Code => Friendly Name)
// Populated with defaults to prevent UI issues
$sectionFriendlyMap = $sysOpts['section_friendly_map'] ?? [
    'GAG' => 'General Affairs Group',
    'TKG' => 'Timekeeping Group',
    'PCG' => 'Procurement Group',
    'ACG' => 'Accounting Group',
    'MED' => 'Medical Group',
    'QA'  => 'Quality Assurance',
    'IT'  => 'Information Technology',
    'CCRE' => 'CCRE Group',
    'DOS_OFF' => 'DOS Office Support',
    'GEN_SUP' => 'General Support',
    'DEPOT_EQ' => 'Depot Equipment',
    'CONVEY' => 'Conveyance',
    'MOTOR' => 'Motor Pool',
    'EMT' => 'Emergency Medical Team',
    'SHUNTER' => 'Shunting Group'
];

// [NEW] Disciplinary Policy Options
$violation_options = [];
try {
    $vStmt = $pdo->query("SELECT category, name FROM disciplinary_violations ORDER BY category, name");
    while ($row = $vStmt->fetch(PDO::FETCH_ASSOC)) {
        $violation_options[$row['category']][] = $row['name'];
    }
} catch (Exception $e) {
}

if (empty($violation_options)) {
    $violation_options = [
        "Attendance" => ["Tardiness / Late", "AWOL (Absence Without Leave)", "Abandonment of Work", "Undertime"],
        "Conduct"    => ["Insubordination", "Disrespect to Superior", "Fighting / Assault", "Gambling on Premises"],
        "Honesty"    => ["Dishonesty", "Falsification of Records", "Theft", "Fraud"],
        "Safety"     => ["LSR Violation", "Non-use of PPE", "Unsafe Act", "Safety Negligence"],
        "Performance" => ["Negligence of Duty", "Sleeping on Duty", "Malingering", "Poor Work Performance"]
    ];
}

$rule_options = [];
try {
    $rule_options = $pdo->query("SELECT name FROM company_rules ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
}

if (empty($rule_options)) {
    $rule_options = [
        "Rule I - Attendance and Punctuality",
        "Rule II - Conduct and Decorum",
        "Rule III - Safety and Health",
        "Rule IV - Company Property",
        "Rule V - Honesty and Integrity",
        "Rule VI - General Provisions",
        "Project-Specific Safety Protocol",
        "Data Privacy Policy"
    ];
}

// [NEW] College Courses for Normalization & Suggestions
$college_courses_list = [];
try {
    $stmt = $pdo->query("SELECT course_name, keywords FROM college_courses ORDER BY course_name ASC");
    $college_courses_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback handled in UI
}
