<?php
// ========================================================================
// ACTIVE: STRICT SECURITY CODE (PRODUCTION READY)
// ========================================================================
class Security
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function checkRateLimit($ip, $limit = 60, $seconds = 60)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT request_count, last_request FROM rate_limits WHERE ip_address = ?");
            $stmt->execute([$ip]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            // Table missing? Allow access so Admin can login and run db_status.php to fix it.
            return true;
        }

        $currentTime = time();

        if ($row) {
            $lastRequestTime = strtotime($row['last_request']);

            if (($currentTime - $lastRequestTime) < $seconds) {
                if ($row['request_count'] >= $limit) {
                    return false; // Return false instead of dying immediately to let the caller handle the message
                }
                $upd = $this->pdo->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE ip_address = ?");
                $upd->execute([$ip]);
            } else {
                // [FIX] Use PHP time to avoid DB timezone mismatches
                $now = date('Y-m-d H:i:s');
                $upd = $this->pdo->prepare("UPDATE rate_limits SET request_count = 1, last_request = ? WHERE ip_address = ?");
                $upd->execute([$now, $ip]);
            }
        } else {
            $now = date('Y-m-d H:i:s');
            $ins = $this->pdo->prepare("INSERT INTO rate_limits (ip_address, request_count, last_request) VALUES (?, 1, ?)");
            $ins->execute([$ip, $now]);
        }

        return true;
    }

    public function sanitizeInput(array $data)
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $value = trim($value);
            $clean[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }
        return $clean;
    }

    public function generateCSRF()
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function checkCSRF($token)
    {
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            throw new Exception("Invalid CSRF Token");
        }
    }
}

/*
// ========================================================================
// DISABLED: RELAXED DEV MODE
// ========================================================================
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
*/
