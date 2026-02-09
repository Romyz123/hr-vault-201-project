<?php
// Legacy approval endpoint disabled.
// All approvals must be handled via admin_approval.php (requests table).
header("Location: admin_approval.php");
exit;
