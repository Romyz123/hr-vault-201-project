<?php
/* ========================================================================
PRESERVED: OLD STRICT SECURITY CODE (FOR DEPLOYMENT LATER)
Use this when you go live to production for maximum security.
========================================================================
class Security {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function checkRateLimit($ip, $limit = 60, $seconds = 60) {
        $stmt = $this->pdo->prepare("SELECT request_count, last_request FROM rate_limits WHERE ip_address = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        $currentTime = time();

        if ($row) {
            $lastRequestTime = strtotime($row['last_request']);
            
            if (($currentTime - $lastRequestTime) < $seconds) {
                if ($row['request_count'] >= $limit) {
                    header("HTTP/1.1 429 Too Many Requests");
                    die("Error 429: Too many requests. Please wait a moment.");
                }
                $upd = $this->pdo->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE ip_address = ?");
                $upd->execute([$ip]);
            } else {
                $upd = $this->pdo->prepare("UPDATE rate_limits SET request_count = 1, last_request = NOW() WHERE ip_address = ?");
                $upd->execute([$ip]);
            }
        } else {
            $ins = $this->pdo->prepare("INSERT INTO rate_limits (ip_address, request_count, last_request) VALUES (?, 1, NOW())");
            $ins->execute([$ip]);
        }
    }

    public function sanitizeInput(array $data) {
        $clean = [];
        foreach ($data as $key => $value) {
            $value = trim($value);
            $clean[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
        return $clean;
    }

    public function generateCSRF() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function checkCSRF($token) {
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            die("Invalid CSRF Token");
        }
    }
}
========================================================================
*/

// ==========================================
// ACTIVE: RELAXED DEV MODE (COMPATIBLE FIX)
// ==========================================

class Security {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    // Fixed to use 'last_request' to match your database
    public function checkRateLimit($ip, $limit = 1000, $seconds = 60) {
        // 1. Check if the table actually exists first to avoid crashes
        try {
            $stmt = $this->pdo->prepare("SELECT request_count, last_request FROM rate_limits WHERE ip_address = ?");
            $stmt->execute([$ip]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            // If table doesn't exist or column is wrong, just allow access to prevent lockout during dev
            return true; 
        }

        $currentTime = time();

        if ($row) {
            $lastRequestTime = strtotime($row['last_request']);
            
            if (($currentTime - $lastRequestTime) < $seconds) {
                if ($row['request_count'] >= $limit) {
                    return false; // LOCKED OUT
                }
                $upd = $this->pdo->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE ip_address = ?");
                $upd->execute([$ip]);
            } else {
                $upd = $this->pdo->prepare("UPDATE rate_limits SET request_count = 1, last_request = NOW() WHERE ip_address = ?");
                $upd->execute([$ip]);
            }
        } else {
            $ins = $this->pdo->prepare("INSERT INTO rate_limits (ip_address, request_count, last_request) VALUES (?, 1, NOW())");
            $ins->execute([$ip]);
        }
        
        return true;
    }
}
?>