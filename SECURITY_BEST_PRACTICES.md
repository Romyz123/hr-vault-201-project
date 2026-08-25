# HR 201 Security & Error Handling Best Practices

**Reference Guide for Developers**

---

## 1. DATABASE QUERY VALIDATION PATTERN

### ❌ WRONG

```php
$result = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total = (int)$result; // Could be null/false!
echo "Total: " . ($total / 10); // Division by zero if $result is false
```

### ✅ CORRECT

```php
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
if (!$stmt) {
    error_log("Database query failed");
    $total = 0;
} else {
    $result = $stmt->fetchColumn();
    if ($result === false || $result === null) {
        $total = 0;
    } else {
        $total = (int)$result;
    }
}
echo "Total: " . ($total / 10); // Safe
```

### 💡 RECOMMENDED HELPER FUNCTION

```php
function safeQueryColumn($pdo, $sql, $params = [], $default = null) {
    try {
        $stmt = $pdo->prepare($sql);
        if (!$stmt->execute($params)) {
            error_log("Query failed: " . implode(", ", $stmt->errorInfo()));
            return $default;
        }
        $result = $stmt->fetchColumn();
        return ($result !== false && $result !== null) ? $result : $default;
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        return $default;
    }
}

// Usage
$total = safeQueryColumn($pdo, "SELECT COUNT(*) FROM users", [], 0);
```

---

## 2. SESSION VARIABLE ACCESS PATTERN

### ❌ WRONG

```php
$userId = $_SESSION['user_id']; // Undefined index if not logged in
$role = $_SESSION['role']; // Undefined index
```

### ✅ CORRECT

```php
// Check authentication first
if (!isset($_SESSION['user_id'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Use null coalescing with defaults
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = strtoupper($_SESSION['role'] ?? 'STAFF');

// Validate role is legitimate
$validRoles = ['ADMIN', 'MANAGER', 'HR', 'STAFF', 'EMPLOYEE'];
if (!in_array($role, $validRoles)) {
    error_log("Invalid role in session: $role");
    session_destroy();
    header('Location: login.php');
    exit;
}
```

### 💡 RECOMMENDED HELPER CLASS

```php
class SessionManager {
    public static function isAuthenticated() {
        return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
    }

    public static function getUserId() {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    public static function getUserRole() {
        return strtoupper($_SESSION['role'] ?? 'STAFF');
    }

    public static function requireRole($requiredRoles) {
        if (!self::isAuthenticated()) {
            header('Location: login.php');
            exit;
        }

        $role = self::getUserRole();
        if (!in_array($role, (array)$requiredRoles)) {
            header('HTTP/1.1 403 Forbidden');
            echo "Access Denied";
            exit;
        }
    }

    public static function destroy() {
        $_SESSION = [];
        session_destroy();
    }
}

// Usage
SessionManager::requireRole(['ADMIN', 'MANAGER']);
$userId = SessionManager::getUserId();
$role = SessionManager::getUserRole();
```

---

## 3. PASSWORD VERIFICATION PATTERN

### ❌ WRONG (TIMING ATTACK VULNERABLE)

```php
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

// Only calls password_verify if user exists!
// Attacker can time the difference to learn which usernames exist
if ($user && password_verify($password, $user['password'])) {
    // Success
}
```

### ✅ CORRECT (TIMING ATTACK RESISTANT)

```php
$stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

// Always call password_verify, even if user doesn't exist
// Use a dummy hash if user not found
$passwordHash = $user['password'] ?? '$2y$10$invalidhashthatshouldnevermatchwithanypassword';
$passwordMatches = password_verify($password, $passwordHash);

// Now check both user existence AND password match
if ($user && $passwordMatches) {
    // Success - But don't expose which check failed
}

// Always use same error message for both cases
if (!$user || !$passwordMatches) {
    $_SESSION['login_error'] = "❌ Invalid username or password";
    // Log failed attempt
    $logger->log($user ? $user['id'] : 0, 'LOGIN_FAILED', "Failed login attempt");
    header('Location: login.php');
    exit;
}
```

---

## 4. ERROR HANDLING PATTERN

### ❌ WRONG

```php
try {
    $result = $pdo->query($sql);
} catch (Exception $e) {
    // Silent catch - no logging!
    // User never knows something failed
}
```

### ✅ CORRECT

```php
try {
    $result = $pdo->query($sql);
} catch (PDOException $e) {
    // Log the error for debugging
    error_log("Database error: " . $e->getMessage());

    // Show user-friendly message (not technical details!)
    $_SESSION['error'] = "Unable to process your request. Please try again.";

    // Redirect or set flag
    header('Location: index.php');
    exit;
}
```

### 💡 RECOMMENDED HELPER FUNCTION

```php
function handleDatabaseError($e, $logger, $fallbackUrl = 'index.php') {
    // Log with context
    error_log("Database Error: " . $e->getMessage());
    error_log("SQL: " . $e->getCode());

    // Notify dev
    if (defined('ADMIN_EMAIL')) {
        mail(ADMIN_EMAIL, "Database Error",
            "Error: " . $e->getMessage() . "\n" .
            "Time: " . date('Y-m-d H:i:s') . "\n" .
            "IP: " . $_SERVER['REMOTE_ADDR']);
    }

    // Tell user something went wrong (generically)
    $_SESSION['error'] = "🔴 System error. Please contact support.";
    header("Location: $fallbackUrl");
    exit;
}

// Usage
try {
    $pdo->query("SELECT * FROM users");
} catch (PDOException $e) {
    handleDatabaseError($e, $logger);
}
```

---

## 5. INPUT SANITIZATION PATTERN

### ❌ WRONG

```php
$name = $_POST['name']; // Could contain HTML/JavaScript!
echo "Hello $name"; // XSS vulnerability
```

### ✅ CORRECT

```php
$name = htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8');
echo "Hello $name";

// Or use parameterized queries (BEST for database)
$stmt = $pdo->prepare("UPDATE users SET name = ? WHERE id = ?");
$stmt->execute([$_POST['name'], $userId]);
```

### 💡 RECOMMENDED HELPER FUNCTION

```php
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function getSafeInput($key, $default = '', $maxLength = 255) {
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    $value = trim((string)$value);

    // Truncate if too long
    if (strlen($value) > $maxLength) {
        $value = substr($value, 0, $maxLength);
    }

    return $value;
}

// Usage
$name = h(getSafeInput('name', '', 100));
echo "Hello $name";
```

---

## 6. ARRAY/COLLECTION VALIDATION PATTERN

### ❌ WRONG

```php
foreach ($employees as $emp) {
    $id = $emp['id']; // What if 'id' doesn't exist?
    $dept = $emp['department']; // What if this is null?
}
```

### ✅ CORRECT

```php
foreach ($employees as $emp) {
    if (!is_array($emp) || !isset($emp['id']) || !isset($emp['department'])) {
        error_log("Invalid employee data: " . json_encode($emp));
        continue; // Skip this record
    }

    $id = (int)$emp['id'];
    $dept = trim($emp['department'] ?? '');

    if (empty($id) || empty($dept)) {
        error_log("Missing required fields for employee");
        continue;
    }

    // Now safe to use
    processEmployee($id, $dept);
}
```

---

## 7. TOKEN VALIDATION PATTERN

### ✅ CORRECT (Already in HR 201)

```php
// CSRF Token Validation
if (empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    header("HTTP/1.1 403 Forbidden");
    echo "CSRF token mismatch";
    exit;
}

// ALWAYS use hash_equals() to prevent timing attacks on tokens!
```

---

## 8. FIELD VALIDATION PATTERN

### ❌ WRONG

```php
if ($_POST['email']) { // Empty string is falsy!
    // This won't validate empty strings
}
```

### ✅ CORRECT

```php
// Check for existence and non-empty value
if (!isset($_POST['email']) || empty(trim($_POST['email']))) {
    $errors[] = "Email is required";
}

// Validate format
if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Email format is invalid";
}

// Use prepared statements for database operations
$stmt = $pdo->prepare("INSERT INTO users (email) VALUES (?)");
$stmt->execute([$_POST['email']]);
```

---

## 9. WHEN TO USE EACH PATTERN

| Situation          | Pattern                                 | Example                           |
| ------------------ | --------------------------------------- | --------------------------------- |
| Display user input | `h()` or `htmlspecialchars()`           | `<?= h($user['name']) ?>`         |
| Store in database  | Prepared statements                     | `$pdo->prepare("WHERE name = ?")` |
| Session values     | Null coalescing                         | `$_SESSION['role'] ?? 'STAFF'`    |
| API responses      | `json_encode()`                         | `json_encode(['status' => 'ok'])` |
| Redirect URLs      | `urlencode()`                           | `?error=' . urlencode($msg)`      |
| File paths         | `realpath()`                            | `realpath($path)`                 |
| Passwords          | `password_hash()` / `password_verify()` | `password_verify($pwd, $hash)`    |

---

## 10. CHECKLIST FOR EVERY PAGE

Before deploying any PHP page, verify:

- [ ] Session check at top: `if (!isset($_SESSION['user_id'])) { exit; }`
- [ ] Role validation if needed: `if ($_SESSION['role'] !== 'ADMIN') { exit; }`
- [ ] CSRF token on all forms: `<input name="csrf_token" value="<?= $csrf_token ?>">`
- [ ] All user input sanitized: `h()` or prepared statements
- [ ] All database queries use prepared statements
- [ ] All errors caught with try/catch and logged
- [ ] No hardcoded credentials or secrets
- [ ] All file operations use `realpath()` to prevent traversal
- [ ] Sensitive operations are rate-limited
- [ ] No `var_dump()`, `print_r()`, or debug output in production

---

## DEPLOYMENT CHECKLIST

Before going live, ensure:

- [ ] All error_logs go to files, not displayed to users
- [ ] No debug variables left in code
- [ ] All critical functions have try/catch blocks
- [ ] Database backups are tested and working
- [ ] SSL/HTTPS is enforced
- [ ] Logging includes IP addresses and timestamps
- [ ] Passwords are hashed with `password_hash()`
- [ ] Session timeout is configured properly
- [ ] Rate limiting is active on login
- [ ] All tests pass
- [ ] Security headers are set in header.php

---

_Version: 1.0 | Updated: April 15, 2026_
