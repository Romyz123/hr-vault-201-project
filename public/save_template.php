<?php
require_once 'includes/db.php';
require_once 'includes/session.php';
require_once 'includes/logger.php';

// Enforce role-based access control for HR and Admin personnel
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['ADMIN', 'HR'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

// Validate request method and CSRF token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token.']);
        exit;
    }

    $slug = trim($_POST['slug'] ?? '');
    $document_name = trim($_POST['document_name'] ?? '');
    $html_content = $_POST['html_content'] ?? ''; // Raw HTML from TinyMCE editor

    if (empty($slug) || empty($document_name) || empty($html_content)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
        exit;
    }

    try {
        // Use an upsert (INSERT ... ON DUPLICATE KEY UPDATE) to manage both creation and updates
        $stmt = $pdo->prepare("
            INSERT INTO document_templates (document_name, slug, html_content) 
            VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                document_name = VALUES(document_name), 
                html_content = VALUES(html_content)
        ");
        $stmt->execute([$document_name, $slug, $html_content]);

        // Log the action for audit compliance
        if (class_exists('AuditLogger')) {
            AuditLogger::log($pdo, $_SESSION['user_id'], 'TEMPLATE_SAVE', "Saved template: {$slug} ({$document_name})");
        }

        echo json_encode(['status' => 'success', 'message' => 'Template successfully saved.']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
}
