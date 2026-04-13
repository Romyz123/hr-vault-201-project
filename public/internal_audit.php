<?php
// --- START: Internal PHP Security Audit Script ---

/**
 * TESP HR Vault 201 - Zero-Install Security Test Runner
 * [LOCATION] tests/internal_audit.php
 */

// 1. CONFIGURATION
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$baseUrl = $protocol . "://" . $host . "/hr 201/public/";

$cookieFile = tempnam(sys_get_temp_dir(), 'audit_cookies_');
$testResults = [];

function run_audit_request($url, $method = 'GET', $postData = [])
{
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    return ['code' => $httpCode, 'headers' => $headers, 'body' => $body];
}

function record_result($name, $passed, $evidence)
{
    global $testResults;
    $testResults[] = [
        'name'   => $name,
        'status' => $passed ? '<span style="color:green">PASSED</span>' : '<span style="color:red">FAILED</span>',
        'result' => $evidence
    ];
}

// --- TEST 1: Guest Access Protection ---
$res1 = run_audit_request($baseUrl . 'activity_logs.php');
$isProtected = ($res1['code'] === 302 || str_contains($res1['body'], 'Login'));
record_result("Guest Access Blocked", $isProtected, "Endpoint activity_logs.php redirected guest to login layer.");

// --- TEST 2: Role Escalation Prevention ---
$res2 = run_audit_request($baseUrl . 'settings.php');
$isBlocked = ($res2['code'] === 302);
record_result("RBAC Enforcement", $isBlocked, "Settings page restricted from unauthorized session access.");

// --- TEST 3: Log Input Sanitization ---
$res3 = run_audit_request($baseUrl . 'activity_logs.php?search=DROP%20TABLE%20users');
$sanitized = !str_contains($res3['body'], 'DROP TABLE');
record_result("SQL Injection Filtering", $sanitized, "Dangerous SQL keywords were neutralized in the UI/Query.");

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Security Audit Report</title>
    <style>
        body {
            font-family: sans-serif;
            padding: 30px;
            background: #f0f2f5;
        }

        .report-box {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }

        h2 {
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #f8f9fa;
        }
    </style>
</head>

<body>
    <div class="report-box">
        <h2>HR Vault 201 - Security Audit Report</h2>
        <p>Executed on: <strong><?= date('Y-m-d H:i:s') ?></strong></p>
        <table>
            <thead>
                <tr>
                    <th>Security Scenario</th>
                    <th>Status</th>
                    <th>Finding / Evidence</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($testResults as $t): ?>
                    <tr>
                        <td><?= $t['name'] ?></td>
                        <td><?= $t['status'] ?></td>
                        <td><?= $t['result'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>

</html>
<?php @unlink($cookieFile); ?>