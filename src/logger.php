<?php
class Logger {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function log($userId, $action, $details) {
        try {
            // Capture IP Address for security context
            $ip = $_SERVER['REMOTE_ADDR'];
            
            $stmt = $this->pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $action, $details, $ip]);
        } catch (PDOException $e) {
            // Silently fail so we don't crash the user's experience if logging fails
            error_log("Logging Error: " . $e->getMessage());
        }
    }
}
?>