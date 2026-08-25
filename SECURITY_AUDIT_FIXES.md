# HR 201 Security Audit - Quick Fix Guide

**Purpose:** Code examples for fixing the 18 identified issues

---

## CRITICAL FIX #1: Ensure Functions Are Defined Before Use

**Problem Files:**

- [public/index.php](public/index.php#L15)
- [public/add_employee.php](public/add_employee.php#L12)
- [public/analytics.php](public/analytics.php#L16)

**Current Code (BROKEN):**

```php
<?php
session_start();
checkSessionTimeout($pdo);  // Fatal Error if db.php not loaded!
require '../config/db.php';
?>
```

**Fixed Code:**

```php
<?php
session_start();
require '../config/db.php';
require '../src/Security.php';
require '../src/Logger.php';

// Now safe to call
checkSessionTimeout($pdo);
?>
```

**Better: Add Defensive Check**

```php
<?php
session_start();
require '../config/db.php';
require '../src/Security.php';

// Defensive check
if (!function_exists('checkSessionTimeout')) {
    error_log("ERROR: checkSessionTimeout function not found!");
    header("Location: login.php?error=" . urlencode("System configuration error"));
    exit;
}

checkSessionTimeout($pdo);
?>
```

---

## CRITICAL FIX #2: Always Check $\_SESSION Before Using

**Problem Files:**

- [public/index.php](public/index.php#L31-L35)
- [public/login.php](public/login.php#L145-L146)
- [public/header.php](public/header.php#L24)

**Current Code (RISKY):**

```php
// Inconsistent approaches throughout codebase
$userRole = isset($_SESSION['user_id']) ? strtoupper((string)$_SESSION['role']) : '';
// vs
$userRole = $userRole ?? strtoupper($_SESSION['role'] ?? '');
```

**Fixed Code - Standardized Pattern:**

```php
<?php
// LOGIN.PHP - After successful authentication
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = strtoupper(trim($user['role']));  // Always uppercase
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['login_time'] = time();
exit("Location: index.php");

// INDEX.PHP - Use safely throughout
$userId = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? 'Unknown';
$userRole = strtoupper($_SESSION['role'] ?? 'STAFF');

// Check for valid authenticated state
if (!$userId || !$userRole) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>
```

**Helper Function for Consistency:**

```php
<?php
class SessionHelper {
    public static function getUserRole() {
        return strtoupper($_SESSION['role'] ?? 'STAFF');
    }

    public static function getUserId() {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    public static function isAuthenticated() {
        return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
    }

    public static function requireAuth() {
        if (!self::isAuthenticated()) {
            session_destroy();
            header("Location: login.php");
            exit;
        }
    }
}

// Usage throughout app
$userId = SessionHelper::getUserId();
$role = SessionHelper::getUserRole();
SessionHelper::requireAuth();
?>
```

---

## CRITICAL FIX #3: Always Check fetchColumn() Returns Before Using

**Problem Files:**

- [public/header.php](public/header.php#L33)
- [public/analytics.php](public/analytics.php#L149, #L302)
- [config/db.php](config/db.php#L135)

**Current Code (BROKEN):**

```php
// WRONG - fetchColumn() can return false/null
$clientTimeout = (int)$stmt->fetchColumn() ?: 900;
$totalHeadcount = (int)$countStmt->fetchColumn();
$avgTenureDays = $avgTenureStmt->fetchColumn();
$avgTenureYears = $avgTenureDays / 365;  // Division by zero!
```

**Fixed Code:**

```php
<?php
// SAFE Pattern 1: Check for false/null explicitly
$result = $stmt->fetchColumn();
if ($result === false || $result === null) {
    $clientTimeout = 900;
} else {
    $clientTimeout = max(60, min((int)$result, 3600));  // Bounds check
}

// SAFE Pattern 2: Using ternary safely
$timeout = ($stmt->fetchColumn() !== false) ? (int)$stmt->fetchColumn() : 900;

// SAFE Pattern 3: Call fetchColumn once and store result
$rawValue = $countStmt->fetchColumn();
$totalHeadcount = ($rawValue !== false && $rawValue) ? (int)$rawValue : 0;

// SAFE Pattern 4: For division/math, always validate
$avgTenureDays = $avgTenureStmt->fetchColumn();
if ($avgTenureDays === false || $avgTenureDays === null || (int)$avgTenureDays <= 0) {
    $avgTenureYears = 0;
} else {
    $avgTenureYears = (int)$avgTenureDays / 365;
}

// SAFE Pattern 5: Best - Use COALESCE in SQL itself
$stmt = $pdo->prepare("SELECT COALESCE(setting_value, ?) AS value FROM system_settings WHERE setting_key = ?");
$stmt->execute(['900', 'session_timeout_client']);
$timeout = (int)$stmt->fetchColumn();  // Now guaranteed to not be false/null
?>
```

**Create a Helper Class:**

```php
<?php
class DBHelper {
    public static function fetchInt($stmt, $default = 0) {
        $value = $stmt->fetchColumn();
        return ($value !== false && $value !== null) ? (int)$value : $default;
    }

    public static function fetchString($stmt, $default = '') {
        $value = $stmt->fetchColumn();
        return ($value !== false && $value !== null) ? (string)$value : $default;
    }

    public static function fetchArray($stmt, $default = []) {
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($result !== false) ? $result : $default;
    }
}

// Usage
$timeout = DBHelper::fetchInt($stmt, 900);
$username = DBHelper::fetchString($stmt, '');
$userData = DBHelper::fetchArray($stmt, []);
?>
```

---

## CRITICAL FIX #4: Remove Goto Statement

**Problem File:**

- [public/index.php](public/index.php#L204)

**Current Code (CODE SMELL):**

```php
if (date('H:i') < $scheduleTime) {
    goto skip_backup;
}

// ... 100+ lines of backup code ...

skip_backup:
// Continue with rest of index.php
```

**Fixed Code:**

```php
<?php
// Extract backup logic into a conditional block
$shouldRunBackup = ($todayDay === $scheduleDay)
                   && empty($existingBackups)
                   && (date('H:i') >= $scheduleTime);

if ($shouldRunBackup) {
    runAutomatedBackup($pdo, $security, $logger);
}

// Or: Extract to a function
function runAutomatedBackup($pdo, $security, $logger) {
    ini_set('memory_limit', '-1');
    set_time_limit(600);

    // All backup logic here
    $baseFilename = 'AutoBackup_' . date('Y-m-d_H-i-s');
    // ...
}
?>
```

---

## HIGH FIX #5: Validate Math Operations

**Problem File:**

- [public/analytics.php](public/analytics.php#L302)

**Current Code (BROKEN):**

```php
$avgTenureDays = $avgTenureStmt->fetchColumn();
$avgTenureYears = $avgTenureDays / 365;  // Crashes if null/false
```

**Fixed Code:**

```php
<?php
$avgTenureDays = $avgTenureStmt->fetchColumn();

// Safe validation before math
if ($avgTenureDays === false || $avgTenureDays === null) {
    $avgTenureYears = 0;
    $avgTenureMonths = 0;
} else {
    $avgTenureDays = (int)$avgTenureDays;
    if ($avgTenureDays > 0) {
        $avgTenureYears = round($avgTenureDays / 365, 2);
        $avgTenureMonths = round($avgTenureDays / 30, 1);
    } else {
        $avgTenureYears = 0;
        $avgTenureMonths = 0;
    }
}

echo "Average Tenure: " . $avgTenureYears . " years";
?>
```

---

## HIGH FIX #6: Fix Type Coercion Issues

**Problem File:**

- [public/header.php](public/header.php#L52-L56)

**Current Code (TYPE RISK):**

```php
$stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout_client'");
$clientTimeout = (int)$stmt->fetchColumn() ?: 900;
```

**Fixed Code:**

```php
<?php
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute(['session_timeout_client']);

    $rawValue = $stmt->fetchColumn();

    // Explicit type checking
    if ($rawValue === false || $rawValue === null) {
        $clientTimeout = 900;
    } else {
        $clientTimeout = (int)$rawValue;
        // Bounds checking
        if ($clientTimeout < 60) {
            $clientTimeout = 60;
        } elseif ($clientTimeout > 3600) {
            $clientTimeout = 3600;
        }
    }
} catch (PDOException $e) {
    error_log("Failed to fetch session timeout: " . $e->getMessage());
    $clientTimeout = 900;
}
?>
```

---

## HIGH FIX #7: Secure OTP Flow

**Problem File:**

- [public/login.php](public/login.php#L137)

**Current Code (MISSING STATE VALIDATION):**

```php
// login.php
$_SESSION['partial_user_id'] = $user['id'];
header("Location: verify_otp.php");
exit;
```

**Fixed Code:**

**In login.php:**

```php
<?php
if ($requires2FA) {
    // Reset attempt counter on successful authentication
    $_SESSION['login_attempts'] = 0;
    unset($_SESSION['last_login_attempt']);

    // Set temporary credentials
    $_SESSION['partial_user_id'] = $user['id'];
    $_SESSION['partial_username'] = $user['username'];
    $_SESSION['otp_timeout'] = time() + 300;  // 5 minute OTP window
    $_SESSION['otp_attempts'] = 0;

    // Do NOT regenerate yet - wait until verification complete
    header("Location: verify_otp.php");
    exit;
}
?>
```

**In verify_otp.php:**

```php
<?php
session_start();

// SECURITY: Validate OTP state FIRST
if (empty($_SESSION['partial_user_id']) || empty($_SESSION['otp_timeout'])) {
    header("Location: login.php?error=" . urlencode("Invalid access. Please log in again."));
    exit;
}

// Check if OTP window expired
if (time() > $_SESSION['otp_timeout']) {
    unset($_SESSION['partial_user_id'], $_SESSION['otp_timeout']);
    header("Location: login.php?error=" . urlencode("OTP expired. Please log in again."));
    exit;
}

// Check OTP attempt limits
if (($_SESSION['otp_attempts'] ?? 0) >= 5) {
    // Lock user account
    $userStmt = $pdo->prepare("UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?");
    $userStmt->execute([$_SESSION['partial_user_id']]);

    unset($_SESSION['partial_user_id'], $_SESSION['otp_timeout']);
    header("Location: login.php?error=" . urlencode("Too many OTP attempts. Account locked for 30 minutes."));
    exit;
}

// Now handle OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = $_POST['otp'] ?? '';
    $_SESSION['otp_attempts'] = ($_SESSION['otp_attempts'] ?? 0) + 1;

    // Verify OTP (using TOTP library)
    $user = $pdo->prepare("SELECT totp_secret FROM users WHERE id = ?");
    $user->execute([$_SESSION['partial_user_id']]);
    $userData = $user->fetch();

    if ($userData && verifyTOTP($otp, $userData['totp_secret'])) {
        // SUCCESS: Complete login
        $userId = $_SESSION['partial_user_id'];

        // Clear temporary data
        unset($_SESSION['partial_user_id'], $_SESSION['partial_username'], $_SESSION['otp_timeout'], $_SESSION['otp_attempts']);

        // CRITICAL: Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        // Set permanent login data
        $_SESSION['user_id'] = $userId;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        // Log successful login
        $logger = new Logger($pdo);
        $logger->log($userId, 'LOGIN_2FA_SUCCESS', "2FA verification completed");

        header("Location: index.php");
        exit;
    } else {
        // Failed OTP
        $_SESSION['otp_error'] = "Invalid OTP. Please try again.";
        header("Location: verify_otp.php");
        exit;
    }
}
?>
```

---

## HIGH FIX #8: Prevent Timing Attacks in Login

**Problem File:**

- [public/login.php](public/login.php#L90)

**Current Code (TIMING ATTACK VULNERABLE):**

```php
// WRONG: Fast if user not found, slow if found
if ($user && password_verify($password, $user['password'])) {
    // Login success
}
```

**Fixed Code:**

```php
<?php
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

// ALWAYS call password_verify for consistent timing
// Step 1: Fetch user (or get dummy hash)
$stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

// Step 2: Use real password if user exists, dummy if doesn't
$passwordToVerify = $user['password'] ?? '$2y$10$aYkR/p.bDz4cgLp3aFexh.VplqQKRB/5TwXplLevYfzXEcjcNEu5m';
// ^ Dummy hash for non-existent users

// Step 3: Always verify (takes same time regardless)
$isPasswordCorrect = password_verify($password, $passwordToVerify);

// Step 4: Check user exists AND password is correct
if ($user && $isPasswordCorrect) {
    // LOGIN SUCCESS
    $_SESSION['user_id'] = $user['id'];
} else {
    // FAILED - but attacker can't tell if user exists
    $logger->log(0, 'LOGIN_FAILED', "Failed login attempt for: $username");
    $_SESSION['login_error'] = "Invalid username or password.";
}
?>
```

---

## HIGH FIX #9: Fix Login Lockout Logic

**Problem File:**

- [public/login.php](public/login.php#L31-L35)

**Current Code (INFINITE LOCKOUT RISK):**

```php
if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= 5) {
    $lockout_time = 15 * 60;
    $time_since_last = time() - ($_SESSION['last_login_attempt'] ?? 0);
    if ($time_since_last < $lockout_time) {
        $lockoutSeconds = $lockout_time - $time_since_last;
    }
}
// No reset if lockout has expired!
```

**Fixed Code:**

```php
<?php
$lockoutSeconds = 0;

if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= 5) {
    $lockout_time = 15 * 60;
    $lastAttempt = $_SESSION['last_login_attempt'] ?? 0;
    $time_since_last = time() - $lastAttempt;

    if ($time_since_last < $lockout_time) {
        // Still in lockout period
        $lockoutSeconds = $lockout_time - $time_since_last;
    } else {
        // Lockout period has expired - reset
        $_SESSION['login_attempts'] = 0;
        unset($_SESSION['last_login_attempt']);
        $lockoutSeconds = 0;
    }
}

// On failed login:
if (!$loginSuccessful) {
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['last_login_attempt'] = time();

    // Check if now locked
    if ($_SESSION['login_attempts'] >= 5) {
        // Log the lockout
        $logger->log($userIdOrZero, 'LOGIN_LOCKOUT', "Account locked after 5 failed attempts");

        // Notify admin
        sendSecurityAlert("Login Lockout", "Multiple failed login attempts for username: $username");
    }
}
?>
```

---

## MEDIUM FIX #10: Check Columns Exist

**Problem File:**

- [public/header.php](public/header.php#L54-L56)

**Current Code:**

```php
$chk = $pdo->query("SHOW COLUMNS FROM documents LIKE 'deleted_at'");
if ($chk->rowCount() > 0) $docQuery .= " AND d.deleted_at IS NULL";
```

**Fixed Code:**

```php
<?php
// Create a reusable helper
function hasColumn($pdo, $table, $column) {
    try {
        $sql = "SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$table, $column]);
        return $stmt->fetchColumn() !== false;
    } catch (Exception $e) {
        error_log("Column check failed: " . $e->getMessage());
        return false;
    }
}

// Usage in header.php
$hasDeletedAt = hasColumn($pdo, 'documents', 'deleted_at');
$hasUploadedBy = hasColumn($pdo, 'documents', 'uploaded_by');

// Build query safely
$docQuery = "SELECT d.id, d.original_name, d.expiry_date FROM documents d WHERE d.is_resolved = 0";
if ($hasDeletedAt) {
    $docQuery .= " AND d.deleted_at IS NULL";
}
?>
```

---

## MEDIUM FIX #11: Fix CSRF Check Order

**Problem File:**

- [public/process_upload.php](public/process_upload.php#L32-L40)

**Current Code (CSRF CHECK TOO LATE):**

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id'])) die("ACCESS DENIED");

    if (empty($_POST)) {
        sendResponse('error', "File too large...");
    }

    // CSRF Token Validation (Check AFTER size check) <- WRONG
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
```

**Fixed Code:**

```php
<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Security checks FIRST - in order

    // 1. Require authentication
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        die("Authentication required");
    }

    // 2. CSRF check BEFORE processing
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(403);
        sendResponse('error', "Security token is invalid or expired. Please refresh and try again.");
    }

    // 3. Rate limiting
    if (!$security->checkRateLimit($_SERVER['REMOTE_ADDR'], 20, 60)) {
        http_response_code(429);
        sendResponse('error', "Too many uploads. Please wait before trying again.");
    }

    // 4. NOW check file size
    if (empty($_FILES)) {
        sendResponse('error', "No file uploaded.");
    }

    if ($_SERVER['CONTENT_LENGTH'] > 128 * 1024 * 1024) {
        sendResponse('error', "File exceeds maximum allowed size (128 MB).");
    }

    // 5. Continue with validation and processing
?>
```

---

## Testing Checklist

After applying fixes:

```
[ ] Test successful login flow
[ ] Test incorrect password (should not reveal if user exists)
[ ] Test 5 failed attempts (should lock)
[ ] Test 15-minute lockout reset
[ ] Test OTP timeout (5 minutes)
[ ] Test OTP with wrong code (5 attempts)
[ ] Test session timeout logic
[ ] Test CSRF token expiration
[ ] Test file upload with large file
[ ] Test database connection failure handling
[ ] Test with null settings in database
[ ] Test with missing database columns
[ ] Test concurrent requests (race conditions)
[ ] Test after server restart (session cleanup)
```

---

**Created:** April 15, 2026  
**Last Updated:** April 15, 2026
