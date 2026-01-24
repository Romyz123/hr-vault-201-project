<?php
// ======================================================
// [FILE] public/test_email.php
// [PURPOSE] Test XAMPP/Gmail SMTP Configuration
// ======================================================

// Enable full error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$msg = "";
$debug_info = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim($_POST['email']);
    $fromName = trim($_POST['from_name'] ?? '') ?: 'HR System';
    $subject = "Test Email from HR System";
    $body = "Success! Your PHP mail configuration is working.\n\nSent at: " . date('Y-m-d H:i:s');
    // Use a generic name, but the actual sending email will be determined by sendmail.ini
    $headers = "From: $fromName <no-reply@hrsystem.com>";

    // Attempt to send
    // Remove '@' to see warnings if any
    if (mail($to, $subject, $body, $headers)) {
        $msg = "<div class='alert alert-success'>✅ <strong>PHP reported success!</strong><br>Check your inbox (and Spam folder).<br>If it doesn't arrive, check <code>C:\xampp\sendmail\error.log</code>.</div>";
    } else {
        $error = error_get_last()['message'] ?? 'Unknown error';
        
        // Try to read XAMPP sendmail log for specific details
        $logPath = 'C:\xampp\sendmail\error.log';
        $logInfo = "";
        if (file_exists($logPath)) {
            $lines = file($logPath);
            if ($lines) {
                $lastLines = array_slice($lines, -3);
                $logInfo = "<hr><strong>Debug Info (from sendmail.log):</strong><br><pre class='mb-0 text-danger'>" . htmlspecialchars(implode("", $lastLines)) . "</pre>";
            }
        }
        
        $msg = "<div class='alert alert-danger'>❌ <strong>Send Failed.</strong><br>PHP Error: $error $logInfo</div>";
    }
}

// Gather Config Info
$smtp = ini_get('SMTP');
$port = ini_get('smtp_port');
$sendmail = ini_get('sendmail_path');

$config_status = "<ul>";
$config_status .= "<li><strong>SMTP (php.ini):</strong> " . ($smtp ?: '<em>Not set (Correct for sendmail)</em>') . "</li>";
$config_status .= "<li><strong>Port (php.ini):</strong> " . ($port ?: '<em>Not set</em>') . "</li>";
$config_status .= "<li><strong>Sendmail Path:</strong> " . htmlspecialchars($sendmail) . "</li>";
$config_status .= "</ul>";

if (strpos($sendmail, 'sendmail.exe') === false) {
    $config_status .= "<div class='alert alert-warning'>⚠️ <strong>Warning:</strong> <code>sendmail_path</code> does not seem to point to <code>sendmail.exe</code>. XAMPP usually requires this for Gmail SMTP.</div>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Email Configuration Tester</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh;">

<div class="card shadow" style="width: 500px;">
    <div class="card-header bg-dark text-white">
        <h5 class="mb-0">📧 SMTP Tester</h5>
    </div>
    <div class="card-body">
        <?php echo $msg; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-bold">Recipient Email</label>
                <input type="email" name="email" class="form-control" placeholder="you@gmail.com" required>
                <div class="form-text">Enter your personal email to receive the test.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">From Name (Optional)</label>
                <input type="text" name="from_name" class="form-control" placeholder="e.g. HR Department" value="HR System">
                <div class="form-text">Test how the sender name appears in your inbox.</div>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-primary">Send Test Email</button>
            </div>
        </form>

        <hr class="my-4">
        
        <h6 class="fw-bold text-secondary">Current PHP Configuration:</h6>
        <div class="small text-muted border p-2 bg-white rounded">
            <?php echo $config_status; ?>
        </div>
        
        <div class="mt-3 small text-muted">
            <strong>Troubleshooting Tips:</strong>
            <ul class="mb-0 ps-3">
                <li>If using Gmail, ensure you generated an <strong>App Password</strong>.</li>
                <li>Check <code>C:\xampp\sendmail\sendmail.ini</code> for correct credentials.</li>
                <li>Check <code>C:\xampp\sendmail\error.log</code> for connection details.</li>
                <li><strong>Error "localhost port 25"?</strong> Edit <code>php.ini</code>: Comment out <code>SMTP</code> and <code>smtp_port</code>, and uncomment <code>sendmail_path</code>.</li>
            </ul>
        </div>
    </div>
    <div class="card-footer text-center">
        <a href="index.php" class="text-decoration-none">Back to Dashboard</a>
    </div>
</div>

</body>
</html>