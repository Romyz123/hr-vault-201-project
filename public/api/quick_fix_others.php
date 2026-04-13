<?php
// public/api/quick_fix_others.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/Logger.php';
session_start();

header('Content-Type: application/json');

// 1. AUTHENTICATION CHECK
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$userRole = strtoupper($_SESSION['role'] ?? '');
$logger = new Logger($pdo);

// 2. LOG API ACCESS (Audit Trail)
$logger->log($uid, 'API_ACCESS', "Accessed Quick Fix Others scanner (IDOR Ownership Check Applied)");

// 3. FETCH REQUIREMENTS
$REQUIRED_DOCS = [];
try {
    $reqStmt = $pdo->query("SELECT name, keywords FROM document_requirements ORDER BY id ASC");
    while ($r = $reqStmt->fetch(PDO::FETCH_ASSOC)) {
        $REQUIRED_DOCS[$r['name']] = array_map('trim', explode(',', $r['keywords']));
    }
} catch (Exception $e) {
    // Fallback if table is not found
}

// 4. SCAN DOCUMENTS WITH OWNERSHIP VALIDATION (IDOR FIX)
// Privileged roles (ADMIN, MANAGER, HR) see all documents.
// Restricted roles (STAFF) see only documents they uploaded.

$sql = "SELECT d.id as doc_id, d.original_name, d.category, d.employee_id, e.first_name, e.last_name 
        FROM documents d 
        JOIN employees e ON d.employee_id = e.emp_id 
        WHERE d.deleted_at IS NULL 
        AND (d.category = 'Others' OR d.category IS NULL OR d.category = '')";

$params = [];

// Check if the 'uploaded_by' column exists for granular ownership validation
$chkUp = $pdo->query("SHOW COLUMNS FROM documents LIKE 'uploaded_by'");
if ($chkUp->rowCount() > 0 && !in_array($userRole, ['ADMIN', 'MANAGER', 'HR'], true)) {
    $sql .= " AND d.uploaded_by = ?";
    $params[] = $uid;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$suggestions = [];
foreach ($docs as $doc) {
    $name = $doc['original_name'];
    foreach ($REQUIRED_DOCS as $reqName => $keywords) {
        foreach ($keywords as $k) {
            if ($k !== '' && stripos($name, $k) !== false) {
                $suggestions[] = [
                    'doc_id' => $doc['doc_id'],
                    'original_name' => $doc['original_name'],
                    'employee_name' => $doc['first_name'] . ' ' . $doc['last_name'],
                    'suggested_category' => $reqName,
                    'matched_keywords' => $k
                ];
                break 2; // Move to the next document once matched
            }
        }
    }
}

echo json_encode([
    'status' => 'success',
    'suggestions' => $suggestions,
    'categories' => array_keys($REQUIRED_DOCS)
]);
exit;
