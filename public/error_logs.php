<?php
// public/error_logs.php
require '../config/db.php';
require '../src/Security.php';
session_start();

// [FIX] Ensure checkSessionTimeout is defined before calling it
if (!function_exists('checkSessionTimeout')) {
    require_once __DIR__ . '/../config/db.php';
}
checkSessionTimeout($pdo);

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'ADMIN') {
    header("Location: index.php");
    exit;
}

$security = new Security($pdo);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$logFile = realpath(__DIR__ . '/../php_error.log');
$msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_log'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        die("CSRF validation failed");
    }
    if ($logFile && file_exists($logFile)) {
        file_put_contents($logFile, "");
        $msg = "✅ Error log cleared successfully.";
    }
}

$logContents = "";
if ($logFile && file_exists($logFile)) {
    $size = filesize($logFile);
    if ($size > 1024 * 1024 * 5) { // If > 5MB, truncate it to save RAM
        $logContents = file_get_contents($logFile, false, null, $size - (1024 * 1024 * 5));
        $logContents = "\n... [Log truncated to last 5MB. Clear log to free space.] ...\n\n" . $logContents;
    } else {
        $logContents = file_get_contents($logFile);
    }
} else {
    $logContents = "No error log file found. System is healthy!";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>PHP Error Logs</title>
    <link rel="icon" href="assets/tesp-logo.png?v=4" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
</head>

<body class="bg-body-tertiary">
    <nav class="navbar navbar-dark bg-dark mb-4">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="index.php">Back to Dashboard</a>
            <div class="d-flex align-items-center gap-2">
                <button id="darkModeToggle" class="btn btn-sm btn-outline-light border-0" title="Toggle Dark Mode">
                    <i class="bi bi-moon-stars-fill"></i>
                </button>
                <span class="navbar-text text-white fw-bold"><i class="bi bi-bug-fill text-danger"></i> PHP Error Logs</span>
            </div>
        </div>
    </nav>
    <div class="container-fluid px-4">
        <?php if ($msg): ?><div class="alert alert-success shadow-sm"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
        <div class="card shadow-sm border-danger">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-terminal"></i> Server Error Log</span>
                <form method="POST" class="m-0" onsubmit="return confirm('Are you sure you want to clear the error log?');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <button type="submit" name="clear_log" class="btn btn-sm btn-light text-danger fw-bold"><i class="bi bi-trash-fill"></i> Clear Log</button>
                </form>
            </div>
            <div class="card-body p-0">
                <pre class="bg-dark text-success p-3 mb-0" style="height: 75vh; overflow-y: auto; font-size: 0.85rem; white-space: pre-wrap; word-wrap: break-word;"><?php echo htmlspecialchars($logContents); ?></pre>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const pre = document.querySelector('pre');
            if (pre) pre.scrollTop = pre.scrollHeight; // Auto-scroll to latest errors
        });
    </script>
</body>

</html>