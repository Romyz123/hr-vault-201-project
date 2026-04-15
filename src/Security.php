<?php
// --- START: UI REPAIR ---
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
            // [SECURITY FIX] Auto-create table if missing, otherwise fail closed
            $errorCode = $e->getCode();
            // SQLSTATE 42S02 = Table doesn't exist (MySQL/MariaDB)
            // SQLSTATE 42P01 = Undefined table (PostgreSQL)
            if ($errorCode === '42S02' || $errorCode === '42P01' || stripos($e->getMessage(), 'doesn\'t exist') !== false) {
                try {
                    $this->pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                        ip_address VARCHAR(45) PRIMARY KEY, 
                        request_count INT DEFAULT 1, 
                        last_request DATETIME
                    )");
                    $row = false;
                } catch (PDOException $createEx) {
                    error_log("Rate limit table creation failed: " . $createEx->getMessage());
                    return false; // Fail closed if we can't create the table
                }
            } else {
                error_log("Rate limit check failed: " . $e->getMessage());
                return false; // Fail closed on unexpected database errors
            }
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
            // [FIX] Rely on PDO for SQLi protection, escape on output
            $clean[$key] = trim($value);
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

    /**
     * Return true if the given user ID belongs to an administrator role.
     */
    public function isAdmin($userId)
    {
        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();
        return $role === 'ADMIN';
    }

    /**
     * Return true if $managerId is the manager for employee $employeeId.
     * Assumes employees table has a manager_id column storing the user ID of the manager.
     */
    public function isManagerOf($managerId, $employeeId)
    {
        $stmt = $this->pdo->prepare("SELECT manager_id FROM employees WHERE emp_id = ? LIMIT 1");
        $stmt->execute([$employeeId]);
        $mgr = $stmt->fetchColumn();
        return $mgr !== false && $mgr == $managerId;
    }

    /**
     * Centralized visibility check; admins and managers of the record can view it,
     * and users may of course view their own record.
     */
    public function canViewEmployee($viewerId, $employeeId)
    {
        if ($viewerId === $employeeId) {
            return true;
        }

        if ($this->isAdmin($viewerId)) {
            return true;
        }

        if ($this->isManagerOf($viewerId, $employeeId)) {
            return true;
        }

        return false;
    }

    // [MHI Security] Check if password was changed in the last 24 hours
    public function checkPasswordFrequency($userId)
    {
        $stmt = $this->pdo->prepare("SELECT password_changed_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $lastChange = $stmt->fetchColumn();

        if ($lastChange) {
            $diff = time() - strtotime($lastChange);
            if ($diff < 86400) { // 86400 seconds = 24 hours
                return false;
            }
        }
        return true;
    }

    // [MHI Security] Check against last 3 passwords
    public function checkPasswordHistory($userId, $newPassword)
    {
        $stmt = $this->pdo->prepare("SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 3");
        $stmt->execute([$userId]);
        $history = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($history as $oldHash) {
            if (password_verify($newPassword, $oldHash)) {
                return false; // Reuse detected
            }
        }
        return true;
    }

    // [MHI Security] Log new password to history
    public function logPasswordHistory($userId, $passwordHash)
    {
        $stmt = $this->pdo->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)");
        $stmt->execute([$userId, $passwordHash]);
    }

    /**
     * [NEW] Encrypts a URL bound to the current system secret and user session.
     * Prevents external link leakage and unauthorized sharing.
     */
    public function maskUrl($url)
    {
        if (empty($url)) return '';
        $config = require __DIR__ . '/../config/config.php';
        $systemSecret = $config['VAULT_KEY'] ?? '';
        $userId = $_SESSION['user_id'] ?? 0;
        $key = hash('sha256', $systemSecret . $userId, true);
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($url, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return bin2hex($iv . $encrypted);
    }

    /**
     * [NEW] Decrypts a URL payload using the user's unique session key.
     */
    public function unmaskUrl($payload)
    {
        if (empty($payload)) return false;
        $config = require __DIR__ . '/../config/config.php';
        $systemSecret = $config['VAULT_KEY'] ?? '';
        $userId = $_SESSION['user_id'] ?? 0;
        $key = hash('sha256', $systemSecret . $userId, true);
        $data = @hex2bin($payload);
        if (!$data || strlen($data) < 16) return false;
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    }
}
?>
<?php // --- END: UI REPAIR --- 
?>
