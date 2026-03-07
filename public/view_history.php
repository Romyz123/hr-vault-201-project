<?php
require '../config/db.php';
require '../src/Security.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die("Access Denied");
}

// grab the viewer and requested employee id
$viewerId   = $_SESSION['user_id'];
$employeeId = $_GET['id'] ?? '';

// validate incoming ID format before querying the database
if (empty($employeeId) || !preg_match('/^[0-9\-]+$/', $employeeId)) {
    http_response_code(400);
    die("Invalid employee ID");
}

// authorization: ensure viewer may see this employee's history
$security = new Security($pdo);
if (!$security->canViewEmployee($viewerId, $employeeId)) {
    http_response_code(403);
    die("Access Denied");
}

// [FIX] Updated table name to 'employment_history' and columns to match schema
$stmt = $pdo->prepare("SELECT * FROM employment_history WHERE employee_id = ? ORDER BY event_date DESC");
$stmt->execute([$employeeId]);
$history = $stmt->fetchAll();
?>

<h5><i class="bi bi-clock-history"></i> Career Timeline</h5>
<ul class="list-group list-group-flush">
    <?php foreach ($history as $h): ?>
        <li class="list-group-item">
            <small class="text-muted"><?php echo $h['event_date'] ? date('M d, Y', strtotime($h['event_date'])) : 'Unknown date'; ?></small><br> <strong><?php echo htmlspecialchars($h['event_title']); ?></strong>
            <?php if (!empty($h['department'])): ?>
                <span class="badge bg-light text-dark border ms-1"><?php echo htmlspecialchars($h['department']); ?></span>
            <?php endif; ?>
            <?php if (!empty($h['notes'])): ?>
                <div class="small text-secondary mt-1"><?php echo nl2br(htmlspecialchars($h['notes'])); ?></div>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    <?php if (empty($history)) echo "<li class='list-group-item text-muted'>No history yet.</li>"; ?>
</ul>