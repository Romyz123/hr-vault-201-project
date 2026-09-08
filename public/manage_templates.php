<?php
require_once '../config/db.php';
require_once '../src/Logger.php';
session_start();

$userRole = isset($_SESSION['role']) ? strtoupper(trim($_SESSION['role'])) : '';
if (!isset($_SESSION['user_id']) || !in_array($userRole, ['ADMIN', 'HR'])) {
    die("ACCESS DENIED");
}

$logger = new Logger($pdo);
$message = '';
$error = '';

// 1. Handle Delete Action
if (isset($_GET['delete'])) {
    $delSlug = $_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM contract_templates WHERE template_slug = ?");
    $stmt->execute([$delSlug]);
    $logger->log($_SESSION['user_id'], 'DELETE_TEMPLATE', "Deleted template: $delSlug");
    header("Location: manage_templates.php?msg=deleted");
    exit;
}

// 2. Handle Save / Upload / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug = trim($_POST['template_slug']);
    $name = trim($_POST['template_name']);
    $content = $_POST['content'] ?? '';

    // If an HTML file was uploaded, read its contents automatically
    if (isset($_FILES['template_file']) && $_FILES['template_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['template_file']['tmp_name'];
        $fileExt = strtolower(pathinfo($_FILES['template_file']['name'], PATHINFO_EXTENSION));

        if ($fileExt === 'html' || $fileExt === 'php') {
            $content = file_get_contents($fileTmpPath);
        } else {
            $error = "Only .html or .php template files are allowed.";
        }
    }

    if (!empty($slug) && !empty($name) && empty($error)) {
        $stmt = $pdo->prepare("REPLACE INTO contract_templates (template_slug, template_name, content) VALUES (?, ?, ?)");
        $stmt->execute([$slug, $name, $content]);
        $logger->log($_SESSION['user_id'], 'SAVE_TEMPLATE', "Saved or updated template: $slug");
        $message = "Template saved successfully!";
    } elseif (empty($error)) {
        $error = "Slug and Template Name are required.";
    }
}

// Fetch template for editing if requested
$editTemplate = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM contract_templates WHERE template_slug = ?");
    $stmt->execute([$_GET['edit']]);
    $editTemplate = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch all templates
$templates = $pdo->query("SELECT template_slug, template_name, updated_at FROM contract_templates ORDER BY template_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Template Manager</title>
    <link href="assets/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-body-tertiary">
    <div class="container my-5">
        <h2>Document Template Manager</h2>
        <p class="text-muted">Upload custom template files or edit layout formats instantly on the fly.</p>

        <?php if ($message || isset($_GET['msg'])): ?>
            <div class="alert alert-success">Operation completed successfully!</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="row">
            <!-- 1. UPLOAD & EDIT FORM -->
            <div class="col-md-7">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white fw-bold">
                        <?php echo $editTemplate ? 'Edit Template: ' . htmlspecialchars($editTemplate['template_name']) : 'Add / Upload New Template'; ?>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Template Slug (Unique ID, e.g., <code>warning_letter</code>)</label>
                                <input type="text" name="template_slug" class="form-control" required value="<?php echo htmlspecialchars($editTemplate['template_slug'] ?? ''); ?>" <?php echo $editTemplate ? 'readonly' : ''; ?>>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Template Display Name</label>
                                <input type="text" name="template_name" class="form-control" required value="<?php echo htmlspecialchars($editTemplate['template_name'] ?? ''); ?>">
                            </div>

                            <!-- Option A: File Upload -->
                            <div class="mb-3 p-3 border rounded bg-light">
                                <label class="form-label fw-bold text-primary">Upload Template File (.html)</label>
                                <input type="file" name="template_file" class="form-control" accept=".html,.php">
                                <small class="text-muted">Instead of typing code, upload a pre-designed HTML file. This will override the text box below.</small>
                            </div>

                            <!-- Option B: Direct Code Textarea -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">Or Edit HTML Layout Content Directly</label>
                                <textarea name="content" class="form-control font-monospace" rows="10"><?php echo htmlspecialchars($editTemplate['content'] ?? ''); ?></textarea>
                            </div>

                            <button type="submit" class="btn btn-success fw-bold">Save Template</button>
                            <?php if ($editTemplate): ?>
                                <a href="manage_templates.php" class="btn btn-secondary">Cancel</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <!-- 2. EXISTING TEMPLATES LIST (With Delete Option) -->
            <div class="col-md-5">
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white fw-bold">Existing Templates</div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($templates as $t): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo htmlspecialchars($t['template_name']); ?></strong><br>
                                    <small class="text-muted">Slug: <code><?php echo htmlspecialchars($t['template_slug']); ?></code></small>
                                </div>
                                <div>
                                    <a href="manage_templates.php?edit=<?php echo urlencode($t['template_slug']); ?>" class="btn btn-sm btn-outline-primary me-1">Edit</a>
                                    <a href="manage_templates.php?delete=<?php echo urlencode($t['template_slug']); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this template?');">Delete</a>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</body>

</html>