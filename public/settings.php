<?php
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';
session_start();

// 1. SECURITY: Only ADMIN or MANAGER
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['ADMIN', 'MANAGER'])) {
    header("Location: index.php");
    exit;
}

// [SECURITY] Check Maintenance Mode
if (($_SESSION['role'] ?? '') !== 'ADMIN') {
    $chkMaint = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn();
    if ($chkMaint === '1') {
        header("Location: login.php?msg=" . urlencode("🛠️ System is under maintenance."));
        exit;
    }
}

$logger = new Logger($pdo);

// 2. HANDLE SAVE
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staff_direct = isset($_POST['staff_direct_approval']) ? '1' : '0';
    $maint_mode   = isset($_POST['maintenance_mode']) ? '1' : '0';

    // Fetch old value for audit comparison
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings");
    $stmt->execute();
    $oldSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Update or Insert
    $sql = "INSERT INTO system_settings (setting_key, setting_value) VALUES 
            ('staff_direct_approval', ?),
            ('maintenance_mode', ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    $pdo->prepare($sql)->execute([$staff_direct, $maint_mode]);

    // [AUDIT LOG] Only log if changed
    if (($oldSettings['staff_direct_approval'] ?? '0') !== $staff_direct) {
        $status = ($staff_direct === '1') ? 'ON' : 'OFF';
        $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', "Changed 'Staff Direct Approval' to $status");
    }

    if (($oldSettings['maintenance_mode'] ?? '0') !== $maint_mode) {
        $status = ($maint_mode === '1') ? 'ON' : 'OFF';
        $logger->log($_SESSION['user_id'], 'SETTINGS_UPDATE', "Changed 'Maintenance Mode' to $status");
    }

    $msg = "✅ Settings updated successfully.";
}

// 3. FETCH CURRENT SETTINGS
$settings = [];
try {
    $stmt = $pdo->query("SELECT * FROM system_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Fallback if table missing
}

$staffDirect = ($settings['staff_direct_approval'] ?? '0') === '1';
$maintMode   = ($settings['maintenance_mode'] ?? '0') === '1';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>System Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>

<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-sliders"></i> System Configuration</h5>
                        <a href="index.php" class="btn btn-sm btn-outline-light">Back to Dashboard</a>
                    </div>
                    <div class="card-body">
                        <?php if ($msg): ?>
                            <div class="alert alert-success"><?php echo $msg; ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <h6 class="border-bottom pb-2 mb-3 text-primary">Permissions & Access</h6>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="staffDirect" name="staff_direct_approval" value="1" <?php echo $staffDirect ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="staffDirect">Allow Staff Direct Edit/Add</label>
                                <div class="form-text text-muted">
                                    If <strong>ON</strong>: Staff changes are saved immediately.<br>
                                    If <strong>OFF</strong>: Staff changes create a "Request" that requires Admin approval.
                                </div>
                            </div>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="maintMode" name="maintenance_mode" value="1" <?php echo $maintMode ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold text-danger" for="maintMode">Maintenance Mode</label>
                                <div class="form-text text-muted">
                                    If <strong>ON</strong>: Only ADMINS can log in. All other users will be blocked.<br>
                                    Use this when performing system updates.
                                </div>
                            </div>

                            <div class="d-grid mt-4">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html