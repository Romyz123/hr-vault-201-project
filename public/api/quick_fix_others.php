<?php
require '../../config/db.php';
require '../../src/Security.php';
session_start();

header('Content-Type: application/json');

// Security: Admin, Manager, HR Only
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER', 'HR'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
    exit;
}
// Fetch dynamic requirements
$REQUIRED_DOCS = [];
try {
    $stmt = $pdo->query("SELECT name, keywords FROM document_requirements ORDER BY id ASC");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $REQUIRED_DOCS[$r['name']] = array_map('trim', explode(',', $r['keywords']));
    }
} catch (Exception $e) {
    // Fallback if table doesn't exist or is empty
    $REQUIRED_DOCS = [
        '201 Files'    => ['201', 'PDS', 'Data Sheet', 'Resume'],
        'Valid ID'     => ['ID', 'Passport', 'License', 'SSS', 'PhilHealth'],
        'Contract'     => ['Contract', 'Appointment', 'Offer'],
        'Medical'      => ['Medical', 'Fit to Work', 'Exam'],
        'Clearance'    => ['NBI', 'Police', 'Barangay']
    ];
}

// Fetch documents categorized as 'Others'
$sql = "SELECT d.id, d.original_name, d.category, e.first_name, e.last_name, e.emp_id 
        FROM documents d
        JOIN employees e ON d.employee_id = e.emp_id
        WHERE d.category = 'Others' AND d.deleted_at IS NULL
        ORDER BY e.last_name, d.original_name";
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $othersDocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('quick_fix_others.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to load suggestion data at this time.']);
    exit;
}

$suggestions = [];
foreach ($othersDocs as $doc) {
    $suggestedCategory = null;
    $matchedKeywords = [];

    foreach ($REQUIRED_DOCS as $reqKey => $keywords) {
        if ($reqKey === 'Others') continue; // Don't suggest 'Others'

        foreach ($keywords as $k) {
            $k = trim($k);
            if ($k === '') continue;

            // Check filename and current category ('Others')
            if (stripos($doc['original_name'], $k) !== false || stripos($doc['category'], $k) !== false) {
                $suggestedCategory = $reqKey;
                $matchedKeywords[] = $k;
                break 2; // Found a match, move to next document
            }
        }
    }

    if ($suggestedCategory) {
        $suggestions[] = [
            'doc_id' => $doc['id'],
            'original_name' => $doc['original_name'],
            'employee_name' => $doc['first_name'] . ' ' . $doc['last_name'] . ' (' . $doc['emp_id'] . ')',
            'current_category' => $doc['category'],
            'suggested_category' => $suggestedCategory,
            'matched_keywords' => implode(', ', $matchedKeywords)
        ];
    } else {
        // Include documents without suggestions so the user can manually categorize them
        $suggestions[] = [
            'doc_id' => $doc['id'],
            'original_name' => $doc['original_name'],
            'employee_name' => $doc['first_name'] . ' ' . $doc['last_name'] . ' (' . $doc['emp_id'] . ')',
            'current_category' => $doc['category'],
            'suggested_category' => null,
            'matched_keywords' => 'None'
        ];
    }
}

echo json_encode(['status' => 'success', 'suggestions' => $suggestions, 'categories' => array_keys($REQUIRED_DOCS)]);
