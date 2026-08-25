<?php
// public/api/get_master_list.php
// [PURPOSE] Safe minimal JSON endpoint for Employee Master Grid

// [FIX] Start buffering immediately to catch any accidental output/notices
ob_start();

require_once '../../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// [SECURITY] Prevent PHP warnings/notices from corrupting the JSON output
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Errors go to logs, NOT to the browser/JSON

// [SECURITY] Authenticated Users Only
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    // [FIX] Check for required columns before querying to prevent 500 errors
    $requiredCols = ['system_role', 'employment_type', 'agency_name'];
    $missing = [];
    foreach ($requiredCols as $col) {
        $chk = $pdo->query("SHOW COLUMNS FROM employees LIKE '$col'");
        if ($chk->rowCount() === 0) $missing[] = $col;
    }

    if (!empty($missing)) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200); // Send 200 so the client can read the JSON error
        echo json_encode([
            'error' => 'Database Out of Date. Missing columns: ' . implode(', ', $missing) . '. Please visit DB Status to run Auto-Fix.'
        ]);
        exit;
    }

    // Select non-sensitive columns for the master grid
    $sql = "SELECT id, emp_id, first_name, last_name, job_title, system_role, dept, section, employment_type, agency_name, hire_date, status 
            FROM employees 
            WHERE deleted_at IS NULL 
            ORDER BY last_name ASC";

    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // [FIX] Finalize clean JSON output
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // Ensure we return an array, even if empty
    echo json_encode($data ?: []);
} catch (PDOException $e) {
    ob_clean();
    error_log("API Error (get_master_list): " . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}
