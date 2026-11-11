<?php
// File: public/api_document_analytics.php
// Returns JSON data for document submission analytics based on the requested grouping.
// Expected GET parameter: group=month|department|employee|type|status

require '../config/db.php';
session_start();
if (isset($_SESSION['user_id'])) checkSessionTimeout($pdo);

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

header('Content-Type: application/json');

$group = $_GET['group'] ?? 'month';
$group = strtolower($group);
$allowedGroups = ['month', 'department', 'employee', 'type', 'status'];
if (!in_array($group, $allowedGroups, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid analytics group']);
    exit;
}

// Helper to safely output JSON and exit
function jsonResponse($data)
{
    echo json_encode($data);
    exit;
}

try {
    switch ($group) {
        case 'month':
            // Count documents per month (uploaded_at)
            $stmt = $pdo->prepare(
                "SELECT DATE_FORMAT(uploaded_at, '%Y-%m') AS month, COUNT(*) AS cnt 
                 FROM documents 
                 WHERE 1=1 
                 AND (deleted_at IS NULL OR deleted_at = '')
                 GROUP BY month 
                 ORDER BY month ASC"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $labels = [];
            $data = [];
            foreach ($rows as $r) {
                $labels[] = $r['month'];
                $data[] = (int)$r['cnt'];
            }
            jsonResponse(['labels' => $labels, 'data' => $data]);
            break;
        case 'department':
            // Documents per department (join employees)
            $stmt = $pdo->prepare(
                "SELECT e.dept AS department, COUNT(*) AS cnt 
                 FROM documents d 
                 JOIN employees e ON d.employee_id = e.emp_id 
                 WHERE (d.deleted_at IS NULL OR d.deleted_at = '') 
                 AND (e.deleted_at IS NULL OR e.deleted_at = '')
                 GROUP BY e.dept 
                 ORDER BY cnt DESC"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $labels = [];
            $data = [];
            foreach ($rows as $r) {
                $labels[] = $r['department'] ?: 'Unassigned';
                $data[] = (int)$r['cnt'];
            }
            jsonResponse(['labels' => $labels, 'data' => $data]);
            break;
        case 'employee':
            // Documents per employee (show name)
            $stmt = $pdo->prepare(
                "SELECT e.id, e.first_name, e.last_name, COUNT(*) AS cnt 
                 FROM documents d 
                 JOIN employees e ON d.employee_id = e.emp_id 
                 WHERE (d.deleted_at IS NULL OR d.deleted_at = '') 
                 AND (e.deleted_at IS NULL OR e.deleted_at = '')
                 GROUP BY e.id 
                 ORDER BY cnt DESC 
                 LIMIT 50" // limit to top 50 for performance
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $labels = [];
            $data = [];
            foreach ($rows as $r) {
                $labels[] = $r['first_name'] . ' ' . $r['last_name'];
                $data[] = (int)$r['cnt'];
            }
            jsonResponse(['labels' => $labels, 'data' => $data]);
            break;
        case 'type':
            // Documents by category (type). Use category column; if null treat as Uncategorized.
            $stmt = $pdo->prepare(
                "SELECT COALESCE(d.category, 'Uncategorized') AS type, COUNT(*) AS cnt 
                 FROM documents d 
                 WHERE (d.deleted_at IS NULL OR d.deleted_at = '')
                 GROUP BY type 
                 ORDER BY cnt DESC"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $labels = [];
            $data = [];
            foreach ($rows as $r) {
                $labels[] = $r['type'];
                $data[] = (int)$r['cnt'];
            }
            jsonResponse(['labels' => $labels, 'data' => $data]);
            break;
        case 'status':
            // Status: Complete vs Missing 201 documents per employee.
            // We consider an employee "Complete" if all required fields are filled.
            $required = ['sss_no', 'tin_no', 'philhealth_no', 'pagibig_no', 'contact_number', 'present_address', 'emergency_name', 'emergency_contact'];
            $placeholders = implode(', ', array_fill(0, count($required), ''));
            // Build a CASE expression counting employees with all required fields non‑empty.
            $case = "CASE WHEN " . implode(' AND ', array_map(function ($f) {
                return "$f <> ''";
            }, $required)) . " THEN 'Complete' ELSE 'Missing' END";
            $stmt = $pdo->prepare(
                "SELECT $case AS status, COUNT(*) AS cnt 
                 FROM employees 
                 WHERE deleted_at IS NULL 
                 GROUP BY status"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $labels = [];
            $data = [];
            foreach ($rows as $r) {
                $labels[] = $r['status'];
                $data[] = (int)$r['cnt'];
            }
            jsonResponse(['labels' => $labels, 'data' => $data]);
            break;
        default:
            // Fallback to month if unknown
            header('HTTP/1.1 400 Bad Request');
            jsonResponse(['error' => 'Invalid group parameter']);
    }
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    error_log('Document analytics error: ' . $e->getMessage());
    jsonResponse(['error' => 'Document analytics is temporarily unavailable']);
}
