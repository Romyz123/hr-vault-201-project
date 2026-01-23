<?php
require '../config/db.php';
$id = $_GET['id'] ?? '';
$stmt = $pdo->prepare("SELECT * FROM employee_history WHERE employee_id = ? ORDER BY change_date DESC");
$stmt->execute([$id]);
$history = $stmt->fetchAll();
?>

<h5><i class="bi bi-clock-history"></i> Career Timeline</h5>
<ul class="list-group list-group-flush">
    <?php foreach($history as $h): ?>
    <li class="list-group-item">
        <small class="text-muted"><?php echo $h['change_date']; ?></small><br>
        <strong><?php echo htmlspecialchars($h['changed_by']); ?></strong>: 
        <?php echo htmlspecialchars($h['details']); ?>
    </li>
    <?php endforeach; ?>
    <?php if(empty($history)) echo "<li class='list-group-item text-muted'>No history yet.</li>"; ?>
</ul>