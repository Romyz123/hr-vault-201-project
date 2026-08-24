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

    public function requireAuthentication()
    {
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            header('Location: login.php');
            exit;
        }
    }

    public function requireRole(array $allowedRoles)
    {
        $this->requireAuthentication();
        $role = strtoupper((string)($_SESSION['role'] ?? ''));
        $allowed = array_map('strtoupper', $allowedRoles);
        if (!in_array($role, $allowed, true)) {
            http_response_code(403);
            header('Location: index.php?error=' . urlencode('Access Denied'));
            exit;
        }
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
                    return false;
                }
                $upd = $this->pdo->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE ip_address = ?");
                $upd->execute([$ip]);
            } else {
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
            $value = trim((string)$value);
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
        $expected = $_SESSION['csrf_token'] ?? null;
        if (!is_string($expected) || !is_string($token) || !hash_equals($expected, $token)) {
            throw new Exception('Invalid CSRF Token');
        }
    }

    public function checkAttemptLimit($key, $limit = 5, $seconds = 600)
    {
        if (!is_string($key) || $key === '') {
            return true;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT request_count, last_request FROM rate_limits WHERE ip_address = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            return true;
        }

        $now = time();
        if ($row) {
            $last = strtotime($row['last_request']);
            if (($now - $last) >= $seconds) {
                $this->pdo->prepare("UPDATE rate_limits SET request_count = 1, last_request = ? WHERE ip_address = ?")->execute([date('Y-m-d H:i:s', $now), $key]);
                return true;
            }
            if ((int)$row['request_count'] >= $limit) {
                return false;
            }
            $this->pdo->prepare("UPDATE rate_limits SET request_count = request_count + 1 WHERE ip_address = ?")->execute([$key]);
            return true;
        }

        $this->pdo->prepare("INSERT INTO rate_limits (ip_address, request_count, last_request) VALUES (?, 1, ?)")->execute([$key, date('Y-m-d H:i:s', $now)]);
        return true;
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
